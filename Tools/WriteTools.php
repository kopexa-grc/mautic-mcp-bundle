<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Tools;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\KopexaMcpBundle\Service\CampaignFlowBuilder;
use MauticPlugin\KopexaMcpBundle\Service\McpUserContext;

const STEPS_SCHEMA = [
            'type'     => 'array',
            'minItems' => 1,
            'maxItems' => 100,
            'items'    => [
                'type'                 => 'object',
                'required'             => ['key', 'type'],
                'additionalProperties' => false,
                'properties'           => [
                    'key'        => ['type' => 'string', 'description' => 'Your id for this step, used by "parent" of later steps, e.g. "welcome"'],
                    'type'       => ['type' => 'string', 'description' => 'Step type from list_campaign_step_types, e.g. "email.send", "email.open", "lead.changetags"'],
                    'name'       => ['type' => 'string', 'description' => 'Name shown in the builder (default: the type label)'],
                    'parent'     => ['type' => 'string', 'description' => 'Key of the step this one follows. Omit for steps that run when a contact enters the campaign. Parents must be listed before their children.'],
                    'path'       => ['type' => 'string', 'enum' => ['yes', 'no'], 'description' => 'Required after a decision or condition: which branch this step is on'],
                    'wait'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'description'          => 'When an action/condition runs, relative to its parent. Omit = immediately. Required on the "no" path of a decision (how long to wait for the contact to act).',
                        'properties'           => [
                            'interval' => ['type' => 'integer', 'minimum' => 1],
                            'unit'     => ['type' => 'string', 'enum' => ['i', 'h', 'd', 'm', 'y'], 'description' => 'i = minutes, h = hours, d = days, m = months, y = years'],
                            'at'       => ['type' => 'string', 'description' => 'Fixed date-time (ISO 8601) instead of an interval'],
                        ],
                    ],
                    'properties' => ['type' => 'object', 'description' => 'Settings of the step type, see describe_campaign_step_type. For email.send: {"email": <template email id>, "email_type": "marketing"|"transactional"}'],
                ],
            ],
        ];

/**
 * Write tools (scope mcp:write). They only create and change *drafts*:
 * everything is saved unpublished, and published or already sent items
 * cannot be changed. Publishing and sending stay a human decision in Mautic.
 */
class WriteTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly EmailModel $emails,
        private readonly ListModel $segments,
        private readonly CampaignModel $campaigns,
        private readonly CoreParametersHelper $parameters,
        private readonly CampaignFlowBuilder $flow,
    ) {
    }

    /**
     * Create an unpublished email draft. Write complete, email-safe HTML (tables, inline styles, max width 600px) and include {unsubscribe_text} in the footer of segment emails. A human reviews and publishes it in Mautic.
     *
     * @param string    $name       Internal name
     * @param string    $subject    Subject line
     * @param string    $html       Full HTML document of the email
     * @param string    $type       "segment" (newsletter sent to segments) or "template" (used by campaigns and forms)
     * @param list<int> $segmentIds Segment IDs for a segment email
     * @param string    $preheader  Preview text shown after the subject
     * @param string    $language   Language code, e.g. "de" or "en"
     *
     * @return array<string, mixed>
     */
    public function createEmailDraft(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        #[Schema(minLength: 1, maxLength: 191)] string $subject,
        #[Schema(minLength: 1)] string $html,
        #[Schema(enum: ['segment', 'template'])] string $type = 'template',
        array $segmentIds = [],
        string $preheader = '',
        string $language = '',
    ): array {
        $this->auth->require($context, 'email:emails:create');

        $email = new Email();
        $email->setName($name);
        $email->setSubject($subject);
        $email->setCustomHtml($html);
        $email->setTemplate('mautic_code_mode');
        $email->setEmailType('segment' === $type ? 'list' : 'template');
        $email->setPreheaderText('' !== $preheader ? $preheader : null);
        $email->setLanguage('' !== $language ? $language : (string) $this->parameters->get('locale', 'en'));
        $email->setDescription('Draft created via MCP. Review before publishing.');
        $email->setIsPublished(false);
        foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
            $email->addList($segment);
        }

        $this->emails->saveEntity($email);
        $this->auth->audit('email', (int) $email->getId(), 'create', ['name' => $name]);

        return $this->emailResult($email, 'Draft created. It is unpublished: review it in Mautic and publish it there.');
    }

    /**
     * Change an email draft. Only unpublished emails that were never sent can be changed. Omitted fields stay as they are.
     *
     * @param int            $id         Email ID
     * @param string|null    $name       New internal name
     * @param string|null    $subject    New subject line
     * @param string|null    $html       New full HTML document
     * @param list<int>|null $segmentIds Replace the segments (segment emails)
     * @param string|null    $preheader  New preview text
     *
     * @return array<string, mixed>
     */
    public function updateEmailDraft(
        RequestContext $context,
        int $id,
        ?string $name = null,
        ?string $subject = null,
        ?string $html = null,
        ?array $segmentIds = null,
        ?string $preheader = null,
    ): array {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw new ToolCallException(sprintf('Email %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'edit');
        if ($email->isPublished() || $email->getSentCount(true) > 0) {
            throw new ToolCallException('Only unpublished drafts that were never sent can be changed via MCP. Unpublish or clone the email in Mautic first.');
        }

        $changed = [];
        if (null !== $name) {
            $email->setName($name);
            $changed[] = 'name';
        }
        if (null !== $subject) {
            $email->setSubject($subject);
            $changed[] = 'subject';
        }
        if (null !== $html) {
            $email->setCustomHtml($html);
            $email->setTemplate('mautic_code_mode');
            $changed[] = 'html';
        }
        if (null !== $preheader) {
            $email->setPreheaderText($preheader);
            $changed[] = 'preheader';
        }
        if (null !== $segmentIds) {
            foreach ($email->getLists()->toArray() as $segment) {
                $email->removeList($segment);
            }
            foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
                $email->addList($segment);
            }
            $changed[] = 'segments';
        }

        $this->emails->saveEntity($email);
        $this->auth->audit('email', (int) $email->getId(), 'update', ['fields' => $changed]);

        return $this->emailResult($email, 'Draft updated ('.implode(', ', $changed ?: ['nothing']).').');
    }

    /**
     * Create an unpublished campaign draft with its segments as source. Build the steps (emails, waits, conditions) in Mautic's campaign builder.
     *
     * @param string    $name        Campaign name
     * @param list<int> $segmentIds  Segments whose contacts enter the campaign
     * @param string    $description What the campaign is for
     *
     * @return array<string, mixed>
     */
    public function createCampaignDraft(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        array $segmentIds,
        string $description = '',
    ): array {
        $this->auth->require($context, 'campaign:campaigns:create');
        if ([] === $segmentIds) {
            throw new ToolCallException('A campaign needs at least one segment as source.');
        }

        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setDescription('' !== $description ? $description : 'Draft created via MCP. Review before publishing.');
        $campaign->setIsPublished(false);
        foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
            $campaign->addList($segment);
        }
        // Show the segment source on the builder canvas.
        $campaign->setCanvasSettings(['nodes' => [['id' => 'lists', 'positionX' => '700', 'positionY' => '50']], 'connections' => []]);

        $this->campaigns->saveEntity($campaign);
        $this->auth->audit('campaign', (int) $campaign->getId(), 'create', ['name' => $name]);

        return $this->campaignResult($campaign, 'Campaign draft created (unpublished). Add the steps in the campaign builder.');
    }

    /**
     * Change name, description or segments of an unpublished campaign. Omitted fields stay as they are.
     *
     * @param int            $id          Campaign ID
     * @param string|null    $name        New name
     * @param string|null    $description New description
     * @param list<int>|null $segmentIds  Replace the source segments
     *
     * @return array<string, mixed>
     */
    public function updateCampaignDraft(RequestContext $context, int $id, ?string $name = null, ?string $description = null, ?array $segmentIds = null): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw new ToolCallException(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'edit');
        if ($campaign->isPublished()) {
            throw new ToolCallException('Published campaigns cannot be changed via MCP. Unpublish it in Mautic first.');
        }

        $changed = [];
        if (null !== $name) {
            $campaign->setName($name);
            $changed[] = 'name';
        }
        if (null !== $description) {
            $campaign->setDescription($description);
            $changed[] = 'description';
        }
        if (null !== $segmentIds) {
            if ([] === $segmentIds) {
                throw new ToolCallException('A campaign needs at least one segment as source.');
            }
            foreach ($campaign->getLists()->toArray() as $segment) {
                $campaign->removeList($segment);
            }
            foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
                $campaign->addList($segment);
            }
            $changed[] = 'segments';
        }

        $this->campaigns->saveEntity($campaign);
        $this->auth->audit('campaign', (int) $campaign->getId(), 'update', ['fields' => $changed]);

        return $this->campaignResult($campaign, 'Campaign draft updated ('.implode(', ', $changed ?: ['nothing']).').');
    }

    /**
     * Set the complete flow (steps) of an UNPUBLISHED campaign: actions, decisions, conditions, waits and yes/no branches. Replaces all existing steps. Steps are validated exactly like in Mautic's campaign builder; the result opens there for review. Example: send welcome email; if opened -> add tag; if not opened within 3 days -> send reminder.
     *
     * @param int                        $id    Campaign ID (must be unpublished)
     * @param list<array<string, mixed>> $steps The steps, parents before children
     *
     * @return array<string, mixed>
     */
    public function setCampaignSteps(RequestContext $context, int $id, #[Schema(definition: STEPS_SCHEMA)] array $steps): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw new ToolCallException(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'edit');
        if ($campaign->isPublished()) {
            throw new ToolCallException('Published campaigns cannot be changed via MCP. Unpublish it in Mautic first.');
        }

        // Emails used by the flow: the user must be allowed to see them, and
        // only template emails can be sent by campaigns.
        foreach ($steps as $step) {
            $emailId = is_array($step) ? ($step['properties']['email'] ?? null) : null;
            if (null === $emailId || !in_array($step['type'] ?? '', ['email.send', 'email.send.to.user'], true)) {
                continue;
            }
            $email = $this->emails->getEntity((int) $emailId);
            if (!$email instanceof Email || !$email->getId()) {
                throw new ToolCallException(sprintf('Step "%s": email %d not found.', $step['key'] ?? '?', (int) $emailId));
            }
            $this->auth->requireEntityAccess($context, $email, 'email:emails', 'view');
            if ('list' === $email->getEmailType()) {
                throw new ToolCallException(sprintf('Step "%s": email %d is a segment email; campaigns can only send template emails (create_email_draft type "template").', $step['key'] ?? '?', $email->getId()));
            }
        }

        $summary = $this->flow->replaceSteps($campaign, array_values($steps));
        $this->auth->audit('campaign', (int) $campaign->getId(), 'set_steps', ['steps' => count($summary)]);

        return $this->campaignResult($campaign, sprintf('Campaign flow saved with %d steps (campaign stays unpublished). Review it in the campaign builder.', count($summary))) + ['steps' => $summary];
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

    /** @return array<string, mixed> */
    private function emailResult(Email $email, string $message): array
    {
        return [
            'id'        => $email->getId(),
            'name'      => $email->getName(),
            'subject'   => $email->getSubject(),
            'published' => $email->isPublished(),
            'message'   => $message,
            'url'       => rtrim((string) $this->parameters->get('site_url'), '/').'/s/emails/view/'.$email->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function campaignResult(Campaign $campaign, string $message): array
    {
        return [
            'id'        => $campaign->getId(),
            'name'      => $campaign->getName(),
            'published' => $campaign->isPublished(),
            'message'   => $message,
            'url'       => rtrim((string) $this->parameters->get('site_url'), '/').'/s/campaigns/view/'.$campaign->getId(),
        ];
    }
}
