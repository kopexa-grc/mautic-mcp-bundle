<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\KopexaMcpBundle\Service\EmailTopics;
use MauticPlugin\KopexaMcpBundle\Service\McpUserContext;

/**
 * Publishing, unpublishing and deleting emails: the steps after a draft.
 * Each has its own opt-in scope (mcp:emails:publish, mcp:emails:delete)
 * and Mautic's publish/delete permission, and reports its effects first
 * with dryRun.
 *
 * - Publishing a SEGMENT email sends it: Mautic's broadcast job (cron)
 *   mails every member of its segments who has not received it.
 * - Publishing a TEMPLATE email makes it usable; it is sent when a running
 *   campaign step or a form action uses it.
 * - Only unpublished emails can be deleted, and none that a campaign uses
 *   (the campaign step would break). Deleting removes its statistics.
 */
class EmailLifecycleTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly EmailModel $emails,
        private readonly EmailTopics $topics,
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /**
     * Publish an email (publish: true) or take it back (publish: false). Publishing a SEGMENT email SENDS it: Mautic's broadcast job mails all members of its segments who did not get it yet. A template email is sent by the running campaigns and forms that use it. Call with dryRun first, show the user live_effects (recipients, campaigns) and publish only after they confirm. Needs the scope mcp:emails:publish.
     *
     * @param string|null $publishUp Segment emails: start sending at this time (ISO 8601, e.g. 2026-12-01T09:00:00+01:00) instead of at the next broadcast run
     *
     * @return array<string, mixed>
     */
    public function publishEmail(RequestContext $context, int $id, bool $publish = true, ?string $publishUp = null, bool $dryRun = false): array
    {
        $email = $this->email($id);
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'publish');

        $start = null;
        if (null !== $publishUp && '' !== trim($publishUp)) {
            if (!$publish) {
                throw new ToolCallException('publishUp only applies when publishing.');
            }
            try {
                $start = new \DateTime($publishUp);
            } catch (\Exception) {
                throw new ToolCallException(sprintf('publishUp "%s" is not a date and time (ISO 8601).', $publishUp));
            }
        }
        // Marketing to segments needs an unsubscribe link; service emails (template) may go without.
        if ($publish && 'list' === $email->getEmailType() && !str_contains((string) $email->getCustomHtml(), '{unsubscribe_url}') && !str_contains((string) $email->getCustomHtml(), '{unsubscribe_text}')) {
            throw new ToolCallException('The segment email has no unsubscribe link ({unsubscribe_url}). Add one before publishing.');
        }

        $result = [
            'id'           => $email->getId(),
            'name'         => $email->getName(),
            'type'         => 'list' === $email->getEmailType() ? 'segment' : 'template',
            'published'    => $email->isPublished(),
            'live_effects' => $this->effects($email, $publish, $start),
            'url'          => $this->url($email),
        ] + $this->topics->describe($email);

        if ($publish === $email->isPublished() && null === $start) {
            return ['message' => $publish ? 'Already published.' : 'Already unpublished.'] + $result;
        }
        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing changed. Show the user live_effects before publishing.'] + $result;
        }

        $email->setIsPublished($publish);
        if (null !== $start) {
            $email->setPublishUp($start);
        }
        $this->emails->saveEntity($email);
        $this->auth->audit('email', (int) $email->getId(), $publish ? 'publish' : 'unpublish', null !== $start ? ['publishUp' => $start->format(DATE_ATOM)] : []);

        return ['message' => $publish ? 'Published.' : 'Unpublished: no further sends.', 'published' => $publish] + $result;
    }

    /**
     * Delete an email. Only unpublished emails that no campaign uses can be deleted (unpublish first with publish_email). Its statistics are deleted with it. Call with dryRun first and show the user what is lost. Needs the scope mcp:emails:delete.
     *
     * @return array<string, mixed>
     */
    public function deleteEmail(RequestContext $context, int $id, bool $dryRun = false): array
    {
        $email = $this->email($id);
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'delete');
        if ($email->isPublished()) {
            throw new ToolCallException('The email is published. Unpublish it first (publish_email with publish: false).');
        }
        $campaigns = $this->campaignsUsing($email);
        if ([] !== $campaigns) {
            throw new ToolCallException('Campaigns use this email; deleting it would break their steps: '.implode(', ', array_map(fn (array $c): string => sprintf('"%s" (%d)', $c['name'], $c['id']), $campaigns)).'. Remove the steps first.');
        }

        $result = [
            'id'    => $email->getId(),
            'name'  => $email->getName(),
            'lost'  => ['sent' => (int) $email->getSentCount(true), 'read' => (int) $email->getReadCount(true), 'note' => 'Statistics of this email are deleted with it.'],
        ];
        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing deleted.'] + $result;
        }
        $this->emails->deleteEntity($email);
        $this->auth->audit('email', $id, 'delete', ['name' => $result['name']]);

        return ['message' => 'Deleted.'] + $result;
    }

    private function email(int $id): Email
    {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw new ToolCallException(sprintf('Email %d not found.', $id));
        }

        return $email;
    }

    /** @return array<string, mixed> */
    private function effects(Email $email, bool $publish, ?\DateTime $start): array
    {
        $campaigns = $this->campaignsUsing($email);
        if (!$publish) {
            return [
                'campaigns' => $campaigns,
                'note'      => 'list' === $email->getEmailType()
                    ? 'Mautic stops sending it to segment members who have not received it yet.'
                    : 'Campaign steps and forms that use it can no longer send it.',
            ];
        }
        if ('list' === $email->getEmailType()) {
            // Members of its segments who did not get it (as on publish, before the date rules).
            $pending = (int) $this->emails->getPendingLeads($email, null, true);

            return [
                'sends'      => true,
                'recipients' => $pending,
                'segments'   => array_map(fn ($s): array => ['id' => (int) $s->getId(), 'name' => (string) $s->getName()], $email->getLists()->toArray()),
                'when'       => null !== $start ? $start->format(DATE_ATOM) : 'with the next broadcast run (cron)',
                'note'       => sprintf('Publishing SENDS this email to %d contact(s), except Do-Not-Contact and contacts who switched its topic off, and later to new segment members.', $pending),
            ];
        }

        return [
            'sends'     => [] !== array_filter($campaigns, fn (array $c): bool => $c['published']),
            'campaigns' => $campaigns,
            'note'      => [] === $campaigns
                ? 'Not used by any campaign: publishing alone sends nothing.'
                : 'Published campaigns that use it send it to contacts reaching that step.',
        ];
    }

    /** @return list<array{id: int, name: string, published: bool}> campaigns with a step sending this email */
    private function campaignsUsing(Email $email): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $rows   = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT c.id, c.name, c.is_published FROM {$prefix}campaign_events e JOIN {$prefix}campaigns c ON c.id = e.campaign_id
             WHERE e.channel = 'email' AND e.channel_id = ? AND e.deleted IS NULL ORDER BY c.id",
            [(int) $email->getId()]
        );

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'published' => (bool) $r['is_published']], $rows);
    }

    private function url(Email $email): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').'/s/emails/view/'.$email->getId();
    }
}
