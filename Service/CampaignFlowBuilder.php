<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Form\Type\EventType;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a campaign's event tree (actions, decisions, conditions, waits)
 * from a simple step list, the same way Mautic's campaign builder and API do:
 *
 * - every step is submitted through the step type's own Symfony form, so
 *   properties are validated and normalised exactly as in the UI (works for
 *   plugin-provided step types too);
 * - connections follow the builder's rules (campaignBuilderConnectionsMap,
 *   connectionRestrictions of the step types);
 * - events and canvas are written with CampaignModel::setEvents() and
 *   setCanvasSettings(), so the result opens in the campaign builder.
 */
class CampaignFlowBuilder
{
    private const MAX_STEPS = 100;
    private const UNITS     = ['i', 'h', 'd', 'm', 'y'];

    public function __construct(
        private readonly EventCollector $eventCollector,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
        private readonly CampaignModel $campaigns,
        private readonly EventModel $events,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, list<array{type: string, label: string, description: string}>> */
    public function stepTypes(): array
    {
        $out = [];
        foreach ($this->eventCollector->getEventsArray() as $eventType => $types) {
            foreach ($types as $type => $settings) {
                $out[$eventType.'s'][] = [
                    'type'        => (string) $type,
                    'label'       => $this->translator->trans((string) ($settings['label'] ?? $type)),
                    'description' => isset($settings['description']) ? strip_tags($this->translator->trans((string) $settings['description'])) : '',
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function describe(string $type): array
    {
        [$eventType, $settings] = $this->settings($type);
        $form                   = $this->form($type, $eventType, $settings, []);

        $fields = [];
        if ($form->has('properties')) {
            foreach ($form->get('properties') as $name => $child) {
                $fields[] = $this->describeField((string) $name, $child);
            }
        }
        $restrictions = $settings['connectionRestrictions'] ?? [];

        return [
            'type'              => $type,
            'kind'              => $eventType,
            'label'             => $this->translator->trans((string) ($settings['label'] ?? $type)),
            'description'       => isset($settings['description']) ? strip_tags($this->translator->trans((string) $settings['description'])) : '',
            'properties'        => $fields,
            'allowed_after'     => $restrictions['source'] ?? null,
            'paths'             => in_array($eventType, ['decision', 'condition'], true) ? ['yes', 'no'] : null,
            'wait'              => 'decision' === $eventType ? 'not applicable (decisions wait for the contact)' : 'optional: {interval, unit} or {at}',
        ];
    }

    /**
     * Replace the whole event tree of a campaign.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array<string, mixed>> summary of the created steps
     */
    public function replaceSteps(Campaign $campaign, array $steps): array
    {
        if ([] === $steps || count($steps) > self::MAX_STEPS) {
            throw new ToolCallException(sprintf('Provide 1 to %d steps.', self::MAX_STEPS));
        }

        // 1. Normalise and check the tree.
        $byKey = [];
        foreach (array_values($steps) as $i => $step) {
            $key = trim((string) ($step['key'] ?? ''));
            if ('' === $key || isset($byKey[$key])) {
                throw new ToolCallException(sprintf('Step %d: "key" must be set and unique.', $i + 1));
            }
            [$eventType, $settings] = $this->settings((string) ($step['type'] ?? ''));
            $parent                 = isset($step['parent']) && '' !== $step['parent'] ? (string) $step['parent'] : null;
            if (null !== $parent && !isset($byKey[$parent])) {
                throw new ToolCallException(sprintf('Step "%s": parent "%s" must be defined before it.', $key, $parent));
            }
            $byKey[$key] = $step + ['_i' => $i, '_eventType' => $eventType, '_settings' => $settings, '_parent' => $parent, '_tempId' => 'new'.bin2hex(random_bytes(8))];
        }

        foreach ($byKey as $key => $step) {
            $this->checkConnection($key, $step, null !== $step['_parent'] ? $byKey[$step['_parent']] : null);
        }

        // 2. Submit every step through its form (validation as in the UI).
        $eventData = [];
        foreach ($byKey as $key => $step) {
            $eventData[$step['_tempId']] = $this->submitStep($campaign, $key, $step, null !== $step['_parent'] ? $byKey[$step['_parent']] : null);
        }

        // 3. Canvas: sources, nodes and connections the builder understands.
        $sources = [];
        if ($campaign->getLists()->count() > 0) {
            $sources[] = 'lists';
        }
        if ($campaign->getForms()->count() > 0) {
            $sources[] = 'forms';
        }
        if ([] === $sources) {
            throw new ToolCallException('The campaign has no source. Add segments first (update_campaign_draft segmentIds).');
        }
        $connections = [];
        foreach ($byKey as $step) {
            if (null === $step['_parent']) {
                foreach ($sources as $source) {
                    $connections[] = ['sourceId' => $source, 'targetId' => $step['_tempId'], 'anchors' => ['source' => 'leadsource', 'target' => 'top']];
                }
                continue;
            }
            $parent        = $byKey[$step['_parent']];
            $anchor        = 'action' === $parent['_eventType'] ? 'bottom' : (string) $step['path'];
            $connections[] = ['sourceId' => $parent['_tempId'], 'targetId' => $step['_tempId'], 'anchors' => ['source' => $anchor, 'target' => 'top']];
        }
        $canvas = ['nodes' => $this->layout($byKey, $sources), 'connections' => $connections];

        // 4. Write, as CampaignApiController does, inside one transaction.
        $deleted = array_map(fn (Event $e): array => ['id' => $e->getId()], array_values(array_filter($campaign->getEvents()->toArray(), fn (Event $e): bool => !$e->isDeleted())));
        $conn    = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->campaigns->setEvents($campaign, $eventData, $canvas, $deleted);
            foreach ($campaign->getEvents() as $event) {
                $violations = $this->validator->validate($event);
                if (count($violations) > 0) {
                    throw new ToolCallException(sprintf('Step "%s": %s', $event->getName(), $violations->get(0)->getMessage()));
                }
            }
            $this->campaigns->saveEntity($campaign);
            $this->campaigns->setCanvasSettings($campaign, $canvas);
            if ([] !== $deleted) {
                $this->events->deleteEvents($campaign->getEvents()->toArray(), $deleted);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e instanceof ToolCallException ? $e : new ToolCallException('Saving the campaign steps failed: '.$e->getMessage(), previous: $e);
        }

        $summary = [];
        foreach ($byKey as $key => $step) {
            $data      = $eventData[$step['_tempId']];
            $summary[] = [
                'key'    => $key,
                'type'   => $step['type'],
                'kind'   => $step['_eventType'],
                'name'   => $data['name'],
                'parent' => $step['_parent'],
                'path'   => $step['path'] ?? null,
                'wait'   => match ($data['triggerMode'] ?? null) {
                    'interval' => $data['triggerInterval'].' '.$data['triggerIntervalUnit'],
                    'date'     => $data['triggerDate'] instanceof \DateTimeInterface ? $data['triggerDate']->format(DATE_ATOM) : null,
                    default    => null,
                },
            ];
        }

        return $summary;
    }

    /** @param array<string, mixed>|null $parent */
    private function checkConnection(string $key, array $step, ?array $parent): void
    {
        $kind = $step['_eventType'];
        $path = $step['path'] ?? null;

        if (null === $parent) {
            if (null !== $path) {
                throw new ToolCallException(sprintf('Step "%s": "path" is only used after a decision or condition.', $key));
            }
            $allowed = $step['_settings']['connectionRestrictions']['source'] ?? [];
            if ([] !== array_filter($allowed)) {
                throw new ToolCallException(sprintf('Step "%s" (%s) cannot be the first step; it must follow: %s.', $key, $step['type'], json_encode($allowed)));
            }

            return;
        }

        $parentKind = $parent['_eventType'];
        if (in_array($parentKind, ['decision', 'condition'], true) && !in_array($path, ['yes', 'no'], true)) {
            throw new ToolCallException(sprintf('Step "%s": after the %s "%s" set "path" to "yes" or "no".', $key, $parentKind, $parent['key']));
        }
        if ('action' === $parentKind && null !== $path) {
            throw new ToolCallException(sprintf('Step "%s": "path" is only used after a decision or condition.', $key));
        }
        // Mautic's builder: a decision cannot directly follow a decision.
        if ('decision' === $parentKind && 'decision' === $kind) {
            throw new ToolCallException(sprintf('Step "%s": a decision cannot directly follow the decision "%s". Put an action or condition in between.', $key, $parent['key']));
        }
        // connectionRestrictions of the step types.
        $mustFollow = $step['_settings']['connectionRestrictions']['source'][$parentKind] ?? [];
        if ([] !== $mustFollow && !in_array($parent['type'], $mustFollow, true)) {
            throw new ToolCallException(sprintf('Step "%s" (%s) can only follow: %s.', $key, $step['type'], implode(', ', $mustFollow)));
        }
        $mayLead = $parent['_settings']['connectionRestrictions']['target'][$kind] ?? [];
        if ([] !== $mayLead && !in_array($step['type'], $mayLead, true)) {
            throw new ToolCallException(sprintf('Step "%s" (%s) cannot follow "%s" (%s).', $key, $step['type'], $parent['key'], $parent['type']));
        }
        // "No" path of a decision = contact did not act: needs a wait.
        if ('decision' === $parentKind && 'no' === $path && 'decision' !== $kind && empty($step['wait'])) {
            throw new ToolCallException(sprintf('Step "%s": on the "no" path of a decision set "wait" (how long to wait for the contact), e.g. {"interval": 3, "unit": "d"}.', $key));
        }
    }

    /**
     * @param array<string, mixed>      $step
     * @param array<string, mixed>|null $parent
     *
     * @return array<string, mixed>
     */
    private function submitStep(Campaign $campaign, string $key, array $step, ?array $parent): array
    {
        $kind  = $step['_eventType'];
        $data  = [
            'type'            => $step['type'],
            'eventType'       => $kind,
            'campaignId'      => $campaign->getId(),
            'anchor'          => $step['path'] ?? ('action' === ($parent['_eventType'] ?? null) ? 'bottom' : 'leadsource'),
            'anchorEventType' => $parent['_eventType'] ?? 'source',
        ];
        $form = $this->form($step['type'], $kind, $step['_settings'], $data);

        $submit = [
            'name'       => (string) ($step['name'] ?? ''),
            'anchor'     => $data['anchor'],
            'properties' => is_array($step['properties'] ?? null) ? $step['properties'] : [],
        ];
        if (in_array($kind, ['action', 'condition'], true)) {
            $wait = is_array($step['wait'] ?? null) ? $step['wait'] : [];
            if (isset($wait['at'])) {
                $at = date_create_immutable((string) $wait['at']);
                if (false === $at) {
                    throw new ToolCallException(sprintf('Step "%s": wait.at is not a valid date.', $key));
                }
                $submit += ['triggerMode' => 'date', 'triggerDate' => $at->format('Y-m-d H:i')];
            } elseif (isset($wait['interval'])) {
                $unit = (string) ($wait['unit'] ?? 'd');
                if (!in_array($unit, self::UNITS, true) || (int) $wait['interval'] < 1) {
                    throw new ToolCallException(sprintf('Step "%s": wait needs interval >= 1 and unit one of i (minutes), h, d, m (months), y.', $key));
                }
                $submit += ['triggerMode' => 'interval', 'triggerInterval' => (int) $wait['interval'], 'triggerIntervalUnit' => $unit];
            } else {
                $submit += ['triggerMode' => 'immediate'];
            }
        }

        if (!$form->has('properties')) {
            if ([] !== $submit['properties']) {
                throw new ToolCallException(sprintf('Step "%s": %s has no properties.', $key, $step['type']));
            }
            unset($submit['properties']);
        }
        // Only fields this step type's form has (e.g. decisions have no wait).
        $submit = array_filter($submit, fn (string $field): bool => $form->has($field), ARRAY_FILTER_USE_KEY);

        $form->submit($submit, false);
        if (!$form->isValid()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $origin   = $error->getOrigin();
                $errors[] = ($origin ? $origin->getName().': ' : '').$error->getMessage();
            }
            throw new ToolCallException(sprintf('Step "%s" (%s) is invalid: %s. Use describe_campaign_step_type for its properties.', $key, $step['type'], implode('; ', array_unique($errors))));
        }

        $result = array_merge($data, (array) $form->getData());
        foreach (['anchor', 'anchorEventType', 'campaignId', 'buttons', 'canvasSettings', 'settings'] as $drop) {
            unset($result[$drop]);
        }
        if ('' === trim((string) ($result['name'] ?? ''))) {
            $result['name'] = $this->translator->trans((string) ($step['_settings']['label'] ?? $step['type']));
        }
        $result['id'] = $step['_tempId'];

        return $result;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function settings(string $type): array
    {
        foreach ($this->eventCollector->getEventsArray() as $eventType => $types) {
            if (isset($types[$type])) {
                return [(string) $eventType, $types[$type]];
            }
        }

        throw new ToolCallException(sprintf('Unknown step type "%s". See list_campaign_step_types.', $type));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $data
     */
    private function form(string $type, string $eventType, array $settings, array $data): FormInterface
    {
        return $this->formFactory->create(EventType::class, $data + ['type' => $type, 'eventType' => $eventType], [
            'settings'        => $settings,
            'csrf_protection' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function describeField(string $name, FormInterface $field): array
    {
        $config  = $field->getConfig();
        $out     = [
            'name'     => $name,
            'label'    => is_string($label = $config->getOption('label')) ? $this->translator->trans($label) : null,
            'kind'     => $config->getType()->getBlockPrefix(),
            'required' => (bool) $config->getOption('required'),
        ];
        if ($config->hasOption('multiple') && $config->getOption('multiple')) {
            $out['multiple'] = true;
        }
        if ($config->hasOption('choices') && is_array($choices = $config->getOption('choices'))) {
            $flat = [];
            array_walk_recursive($choices, function ($value, $label) use (&$flat): void {
                $flat[] = ['value' => $value, 'label' => is_string($label) ? $this->translator->trans($label) : (string) $label];
            });
            $out['choices'] = array_slice($flat, 0, 50);
            if (count($flat) > 50) {
                $out['choices_truncated'] = count($flat);
            }
        }
        if (count($field) > 0) {
            $out['fields'] = [];
            foreach ($field as $childName => $child) {
                $out['fields'][] = $this->describeField((string) $childName, $child);
            }
        }

        return $out;
    }

    /**
     * Tree layout for the builder canvas: one row per depth, leaves spread
     * horizontally, parents centred above their children.
     *
     * @param array<string, array<string, mixed>> $byKey
     * @param list<string>                        $sources
     *
     * @return list<array{id: string, positionX: string, positionY: string}>
     */
    private function layout(array $byKey, array $sources): array
    {
        $children = [];
        foreach ($byKey as $key => $step) {
            $children[$step['_parent'] ?? ''][] = $key;
        }
        $x    = [];
        $next = 0;
        $place = function (string $key, int $depth) use (&$place, &$x, &$next, $children): array {
            $xs = [];
            foreach ($children[$key] ?? [] as $child) {
                $xs[] = $place($child, $depth + 1)[0];
            }
            $x[$key] = [[] === $xs ? $next++ : (min($xs) + max($xs)) / 2, $depth];

            return $x[$key];
        };
        $rootXs = [];
        foreach ($children[''] ?? [] as $root) {
            $rootXs[] = $place($root, 1)[0];
        }

        $nodes  = [];
        $center = [] === $rootXs ? 0 : (min($rootXs) + max($rootXs)) / 2;
        foreach ($sources as $i => $source) {
            $nodes[] = ['id' => $source, 'positionX' => (string) (int) (100 + ($center + $i) * 320), 'positionY' => '20'];
        }
        foreach ($byKey as $key => $step) {
            [$col, $depth] = $x[$key];
            $nodes[]       = ['id' => $step['_tempId'], 'positionX' => (string) (int) (100 + $col * 320), 'positionY' => (string) (20 + $depth * 160)];
        }

        return $nodes;
    }
}
