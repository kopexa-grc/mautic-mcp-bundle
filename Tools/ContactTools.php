<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Tools;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\KopexaMcpBundle\Service\McpUserContext;

const CONTACT_SCHEMA = [
    'type'                 => 'object',
    'additionalProperties' => false,
    'properties'           => [
        'email'  => ['type' => 'string', 'description' => 'Email address; identifies existing contacts (with the other unique fields of this Mautic)'],
        'fields' => [
            'type'                 => 'object',
            'description'          => 'Contact fields by alias (see list_contact_fields), e.g. {"firstname": "Alex", "lastname": "Weber", "company": "ACME GmbH", "position": "CISO", "phone": "+49 431 123", "city": "Kiel", "country": "Germany"}',
            'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']],
        ],
        'tags'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags to add; prefix "-" removes a tag, e.g. "-lead"'],
    ],
];

/**
 * Contact and segment tools (scope mcp:contacts:write): create and update
 * contacts, create segments, change segment membership. Built for syncing
 * contacts with other systems (CRMs such as Twenty) through an assistant.
 *
 * Contacts that join a segment can enter campaigns that use the segment
 * and receive published segment emails; the results report these
 * live_effects, and dryRun shows them before anything is saved.
 * Do-Not-Contact (unsubscribed, bounced) is never changed.
 */
class ContactTools
{
    private const MAX_CONTACTS = 100;

    /** @var array<string, string>|null alias => label */
    private ?array $fieldList = null;

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly LeadModel $leads,
        private readonly ListModel $segments,
        private readonly FieldModel $fields,
        private readonly FieldsWithUniqueIdentifier $uniqueFields,
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /**
     * List the contact fields of this Mautic (alias, label, type, whether it identifies contacts). Use the aliases in upsert_contacts.
     *
     * @return array<string, mixed>
     */
    public function listContactFields(RequestContext $context): array
    {
        $this->auth->require($context, ['lead:leads:viewown', 'lead:leads:viewother']);
        $unique = $this->uniqueFields->getFieldsWithUniqueIdentifier(['object' => 'lead']);
        $fields = [];
        foreach ($this->fields->getFieldListWithProperties('lead') as $alias => $f) {
            if (!($f['isPublished'] ?? true)) {
                continue;
            }
            $fields[] = array_filter([
                'alias'      => $alias,
                'label'      => $f['label'] ?? $alias,
                'type'       => $f['type'] ?? null,
                'group'      => $f['group'] ?? null,
                'unique'     => array_key_exists($alias, $unique) ?: null,
                'options'    => $this->fieldOptions($f),
            ], fn ($v): bool => null !== $v && [] !== $v);
        }

        return ['fields' => $fields];
    }

    /**
     * Create or update contacts (up to 100 per call), matched by email and the other unique fields: existing contacts are updated, new ones created. Optionally add them all to segments. Do-Not-Contact status (unsubscribed, bounced) is never changed. Call with dryRun first when adding to segments: live_effects lists running campaigns and segment emails these contacts would get.
     *
     * @param list<array<string, mixed>> $contacts   Contacts: {email, fields, tags}
     * @param list<int>                  $segmentIds Add every contact to these segments
     * @param bool                       $overwrite  Overwrite existing values (false: only fill empty fields of existing contacts)
     * @param bool                       $dryRun     Validate and report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function upsertContacts(
        RequestContext $context,
        #[Schema(definition: ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CONTACTS, 'items' => CONTACT_SCHEMA])] array $contacts,
        array $segmentIds = [],
        bool $overwrite = true,
        bool $dryRun = false,
    ): array {
        $this->auth->user($context);
        if (count($contacts) > self::MAX_CONTACTS) {
            throw new ToolCallException(sprintf('At most %d contacts per call; split the list.', self::MAX_CONTACTS));
        }
        $segments = $this->segmentEntities($context, $segmentIds);
        $known    = $this->fieldList();

        $results = [];
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $seen    = [];
        foreach (array_values($contacts) as $i => $row) {
            try {
                $data = $this->contactData($row, $known);
                $key  = strtolower((string) ($data['email'] ?? ''));
                if ('' !== $key && isset($seen[$key])) {
                    throw new ToolCallException(sprintf('Duplicate of entry %d (same email).', $seen[$key]));
                }
                $seen[$key] = $i;

                $lead  = $this->leads->checkForDuplicateContact($data);
                $isNew = !$lead->getId();
                if ($isNew) {
                    $this->auth->require($context, 'lead:leads:create');
                } else {
                    $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
                }

                if (!$isNew && !$overwrite) {
                    $data = array_filter($data, fn ($v, string $alias): bool => in_array($lead->getFieldValue($alias), [null, ''], true), ARRAY_FILTER_USE_BOTH);
                }
                $changed = $isNew ? array_keys($data) : array_keys(array_filter($data, fn ($v, string $alias): bool => (string) $lead->getFieldValue($alias) !== (string) $v, ARRAY_FILTER_USE_BOTH));
                [$addTags, $removeTags] = $this->splitTags($row['tags'] ?? []);
                $current                = array_map(fn ($t): string => $t->getTag(), $lead->getTags()->toArray());
                $tagChanges             = array_values(array_merge(array_diff($addTags, $current), array_map(fn (string $t): string => '-'.$t, array_intersect($removeTags, $current))));
                $inSegments             = $isNew ? [] : $this->memberSegmentIds((int) $lead->getId(), array_map(fn (LeadList $s): int => (int) $s->getId(), $segments));
                $joins                  = array_values(array_filter($segments, fn (LeadList $s): bool => !in_array((int) $s->getId(), $inSegments, true)));

                $status = $isNew ? 'created' : ([] === $changed && [] === $tagChanges && [] === $joins ? 'unchanged' : 'updated');
                if (!$dryRun && 'unchanged' !== $status) {
                    $this->leads->setFieldValues($lead, $data, false, false);
                    if ([] !== $addTags || [] !== $removeTags) {
                        $this->leads->modifyTags($lead, $addTags, $removeTags, false);
                    }
                    $this->leads->saveEntity($lead);
                    foreach ($joins as $segment) {
                        $this->segments->addLead($lead, $segment, true);
                    }
                    $this->auth->audit('lead', (int) $lead->getId(), $isNew ? 'create' : 'update', ['fields' => $changed, 'tags' => $tagChanges, 'segments' => array_map(fn (LeadList $s): int => (int) $s->getId(), $joins)]);
                }
                ++$counts[$status];
                $results[] = array_filter([
                    'index'          => $i,
                    'status'         => $status,
                    'id'             => $lead->getId(),
                    'email'          => $data['email'] ?? $lead->getEmail(),
                    'changed_fields' => 'updated' === $status ? $changed : null,
                    'tags'           => $tagChanges ?: null,
                    'joins_segments' => array_map(fn (LeadList $s): int => (int) $s->getId(), $joins) ?: null,
                ], fn ($v): bool => null !== $v);
            } catch (ToolCallException $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'email' => $row['email'] ?? null, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'email' => $row['email'] ?? null, 'error' => 'Mautic rejected the contact: '.$e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'message' => ($dryRun ? 'Dry run, nothing saved: ' : 'Saved: ').vsprintf('%d created, %d updated, %d unchanged, %d failed.', array_values($counts)),
            'counts'  => $counts,
            'results' => $results,
        ] + ([] !== $segments ? ['live_effects' => $this->liveEffects($segments)] : []);
    }

    /**
     * Create a segment. Without filters it is a static list: contacts are added with upsert_contacts or add_contacts_to_segment.
     *
     * @param string $name        Segment name
     * @param string $description What the segment is for
     * @param string $publicName  Name shown to contacts in the preference center (default: name)
     *
     * @return array<string, mixed>
     */
    public function createSegment(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        string $description = '',
        string $publicName = '',
    ): array {
        $this->auth->require($context, 'lead:lists:create');
        $segment = new LeadList();
        $segment->setName($name);
        $segment->setDescription('' !== $description ? $description : 'Created via MCP.');
        $segment->setPublicName('' !== $publicName ? $publicName : $name);
        $segment->setFilters([]);
        $segment->setIsPublished(true);
        $this->segments->saveEntity($segment);
        $this->auth->audit('segment', (int) $segment->getId(), 'create', ['name' => $name]);

        return [
            'id'      => $segment->getId(),
            'name'    => $segment->getName(),
            'alias'   => $segment->getAlias(),
            'message' => 'Segment created (static, no filters). Search its contacts with search_contacts "segment:'.$segment->getAlias().'".',
            'url'     => $this->adminUrl('/s/segments/view/'.$segment->getId()),
        ];
    }

    /**
     * Add contacts to a segment (up to 100 per call). They can enter running campaigns that use the segment and receive its published segment emails: call with dryRun first and show the user live_effects.
     *
     * @param int       $segmentId  Segment ID
     * @param list<int> $contactIds Contact IDs
     * @param bool      $dryRun     Report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function addContactsToSegment(RequestContext $context, int $segmentId, #[Schema(minItems: 1, maxItems: self::MAX_CONTACTS)] array $contactIds, bool $dryRun = false): array
    {
        return $this->changeMembership($context, $segmentId, $contactIds, true, $dryRun);
    }

    /**
     * Remove contacts from a segment (up to 100 per call). They leave campaigns that use the segment only if those campaigns are set to remove contacts; Do-Not-Contact is not changed.
     *
     * @param int       $segmentId  Segment ID
     * @param list<int> $contactIds Contact IDs
     * @param bool      $dryRun     Report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function removeContactsFromSegment(RequestContext $context, int $segmentId, #[Schema(minItems: 1, maxItems: self::MAX_CONTACTS)] array $contactIds, bool $dryRun = false): array
    {
        return $this->changeMembership($context, $segmentId, $contactIds, false, $dryRun);
    }

    /**
     * @param array<mixed> $contactIds
     *
     * @return array<string, mixed>
     */
    private function changeMembership(RequestContext $context, int $segmentId, array $contactIds, bool $add, bool $dryRun): array
    {
        [$segment] = $this->segmentEntities($context, [$segmentId]);
        $ids       = array_values(array_unique(array_map('intval', $contactIds)));
        if (count($ids) > self::MAX_CONTACTS) {
            throw new ToolCallException(sprintf('At most %d contacts per call; split the list.', self::MAX_CONTACTS));
        }

        $members = $this->memberIds((int) $segment->getId(), $ids);
        $results = [];
        $changed = 0;
        foreach ($ids as $id) {
            $lead = $this->leads->getEntity($id);
            if (!$lead instanceof Lead || !$lead->getId()) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error' => 'Contact not found.'];
                continue;
            }
            try {
                $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
            } catch (ToolCallException $e) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                continue;
            }
            $isMember = in_array($id, $members, true);
            if ($add === $isMember) {
                $results[] = ['id' => $id, 'status' => $add ? 'already_member' : 'not_member'];
                continue;
            }
            if (!$dryRun) {
                $add ? $this->segments->addLead($lead, $segment, true) : $this->segments->removeLead($lead, $segment, true);
            }
            ++$changed;
            $results[] = ['id' => $id, 'status' => $add ? 'added' : 'removed'];
        }
        if (!$dryRun && $changed > 0) {
            $this->auth->audit('segment', (int) $segment->getId(), $add ? 'add_contacts' : 'remove_contacts', ['contacts' => array_column(array_filter($results, fn (array $r): bool => in_array($r['status'], ['added', 'removed'], true)), 'id')]);
        }

        return [
            'dry_run'  => $dryRun,
            'segment'  => ['id' => $segment->getId(), 'name' => $segment->getName()],
            'message'  => sprintf('%s%d contact(s) %s.', $dryRun ? 'Dry run, nothing saved: ' : '', $changed, $add ? 'added' : 'removed'),
            'results'  => $results,
        ] + ($add ? ['live_effects' => $this->liveEffects([$segment])] : []);
    }

    /**
     * What joining these segments sets in motion: running campaigns that use
     * them (Mautic's scheduler adds new members) and published segment
     * emails (the broadcast job sends them to members who did not get them).
     *
     * @param list<LeadList> $segments
     *
     * @return array<string, mixed>
     */
    private function liveEffects(array $segments): array
    {
        $ids    = array_map(fn (LeadList $s): int => (int) $s->getId(), $segments);
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $campaigns = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT c.id, c.name FROM {$prefix}campaigns c JOIN {$prefix}campaign_leadlist_xref x ON x.campaign_id = c.id
             WHERE x.leadlist_id IN (?) AND c.is_published = 1 AND c.deleted IS NULL ORDER BY c.id",
            [$ids], [ArrayParameterType::INTEGER]
        );
        $emails = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT e.id, e.name, e.subject FROM {$prefix}emails e JOIN {$prefix}email_list_xref x ON x.email_id = e.id
             WHERE x.leadlist_id IN (?) AND e.is_published = 1 AND e.email_type = 'list'
               AND (e.publish_down IS NULL OR e.publish_down > ?) ORDER BY e.id",
            [$ids, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')], [ArrayParameterType::INTEGER, ParameterType::STRING]
        );
        $cast = fn (array $rows): array => array_map(fn (array $r): array => ['id' => (int) $r['id']] + $r, $rows);

        return [
            'running_campaigns'        => $cast($campaigns),
            'published_segment_emails' => $cast($emails),
            'note'                     => [] === $campaigns && [] === $emails
                ? 'No running campaign or published segment email uses these segments.'
                : 'New members enter these campaigns with the next campaign run and get these segment emails with the next broadcast run (cron), unless they are Do-Not-Contact.',
        ];
    }

    /**
     * Which of these segments the contact is a member of.
     *
     * @param list<int> $segmentIds
     *
     * @return list<int>
     */
    private function memberSegmentIds(int $leadId, array $segmentIds): array
    {
        if ([] === $segmentIds) {
            return [];
        }
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT leadlist_id FROM {$prefix}lead_lists_leads WHERE lead_id = ? AND manually_removed = 0 AND leadlist_id IN (?)",
            [$leadId, $segmentIds], [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        ));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function memberIds(int $segmentId, array $ids): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT lead_id FROM {$prefix}lead_lists_leads WHERE leadlist_id = ? AND manually_removed = 0 AND lead_id IN (?)",
            [$segmentId, $ids], [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        ));
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $known
     *
     * @return array<string, mixed>
     */
    private function contactData(array $row, array $known): array
    {
        $data = [];
        foreach ((array) ($row['fields'] ?? []) as $alias => $value) {
            $data[strtolower((string) $alias)] = is_bool($value) ? (int) $value : $value;
        }
        if (isset($row['email'])) {
            $data['email'] = $row['email'];
        }
        if (isset($data['email'])) {
            $data['email'] = trim((string) $data['email']);
            if (false === filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new ToolCallException(sprintf('Invalid email address "%s".', $data['email']));
            }
        }
        $unknown = array_diff(array_keys($data), array_keys($known));
        if ([] !== $unknown) {
            throw new ToolCallException(sprintf('Unknown contact field(s): %s. See list_contact_fields.', implode(', ', $unknown)));
        }
        if (in_array($data['email'] ?? '', ['', null], true) && [] === array_filter($data, fn ($v): bool => !in_array($v, [null, ''], true))) {
            throw new ToolCallException('A contact needs an email address or other field values.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $tags
     *
     * @return array{list<string>, list<string>}
     */
    private function splitTags(array $tags): array
    {
        $add = $remove = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if (str_starts_with($tag, '-')) {
                $remove[] = trim(substr($tag, 1));
            } elseif ('' !== $tag) {
                $add[] = $tag;
            }
        }

        return [array_values(array_unique(array_filter($add))), array_values(array_unique(array_filter($remove)))];
    }

    /** @return array<string, string> */
    private function fieldList(): array
    {
        return $this->fieldList ??= $this->fields->getFieldList(false, false, ['isPublished' => true, 'object' => 'lead']);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return list<string>|null
     */
    private function fieldOptions(array $field): ?array
    {
        $list = $field['properties']['list'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $values = [];
        foreach ($list as $k => $option) {
            $values[] = (string) (is_array($option) ? ($option['value'] ?? $k) : $k);
        }

        return array_slice($values, 0, 50) ?: null;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<LeadList>
     */
    private function segmentEntities(RequestContext $context, array $ids): array
    {
        $segments = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $segment = $this->segments->getEntity($id);
            if (!$segment instanceof LeadList || !$segment->getId()) {
                throw new ToolCallException(sprintf('Segment %d not found.', $id));
            }
            $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'view');
            $segments[] = $segment;
        }

        return $segments;
    }

    private function adminUrl(string $path): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').$path;
    }
}
