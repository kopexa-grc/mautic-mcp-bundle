<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * Changes to LIVE emails (published or already sent). Drafts are changed
 * freely with mcp:write. On a live email, everything recipients get (content,
 * sender, segments, a changed or removed topic) applies to its next sends at
 * once, so it also needs the opt-in scope mcp:emails:publish; the tools report
 * live_effects (also in a dryRun). Only the internal name and adding a topic
 * to an email without one (that only narrows its recipients) need no more.
 */
class EmailLiveEffects
{
    /** Fields that change nothing for recipients. */
    private const SAFE_FIELDS = ['name'];

    public function __construct(
        private readonly EmailModel $emails,
        private readonly Connection $connection,
        private readonly McpUserContext $auth,
    ) {
    }

    public function isLive(Email $email): bool
    {
        return (bool) $email->getIsPublished() || $email->getSentCount(true) > 0;
    }

    /**
     * Checks the scope for changing $fields of a live email and returns its live_effects (null for drafts).
     * Call before applying the changes.
     *
     * @param list<string> $fields changed fields; "topic" only if it changes or removes an existing topic
     *
     * @return array<string, mixed>|null
     */
    public function guard(RequestContext $context, Email $email, array $fields): ?array
    {
        if (!$this->isLive($email)) {
            return null;
        }
        $live = array_values(array_diff($fields, self::SAFE_FIELDS));
        if ([] !== $live && !$this->auth->hasScope($context, McpScopes::EMAILS_PUBLISH)) {
            throw new ToolCallException(sprintf(
                'The email is %s: changing %s applies to its recipients at once. That needs the scope mcp:emails:publish (call with dryRun first and show the user live_effects), or change it in Mautic.',
                $email->getIsPublished() ? 'published' : 'already sent',
                implode(', ', $live)
            ));
        }

        return $this->effects($email, $live);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    private function effects(Email $email, array $fields): array
    {
        $sent      = (int) $email->getSentCount(true);
        $campaigns = $this->campaignsUsing($email);
        $out       = [
            'live'          => [] !== $fields,
            'live_fields'   => $fields,
            'already_sent'  => $sent,
            'campaigns'     => $campaigns,
        ];
        if ([] === $fields) {
            return $out + ['note' => 'Nothing recipients see changes.'];
        }
        if (!$email->getIsPublished()) {
            return $out + ['note' => sprintf('Unpublished: nothing is sent now; the changes apply once it is published again. The %d contact(s) who got it keep their version.', $sent)];
        }
        if ('list' === $email->getEmailType()) {
            $pending = (int) $this->emails->getPendingLeads($email, null, true);

            return $out + [
                'pending_recipients' => $pending,
                'note'               => sprintf('Published segment email: %d contact(s) who have not got it yet receive the NEW version with the next broadcast run. The %d who got it keep theirs; its statistics now mix both versions.', $pending, $sent),
            ];
        }
        $running = array_filter($campaigns, fn (array $c): bool => $c['published']);

        return $out + ['note' => [] === $running
            ? 'Published template email, used by no running campaign: forms that use it send the new version.'
            : 'Running campaigns that use it send the NEW version to contacts reaching that step from now on.'];
    }

    /** @return list<array{id: int, name: string, published: bool}> campaigns with a step sending this email */
    public function campaignsUsing(Email $email): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $rows   = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT c.id, c.name, c.is_published FROM {$prefix}campaign_events e JOIN {$prefix}campaigns c ON c.id = e.campaign_id
             WHERE e.channel = 'email' AND e.channel_id = ? AND e.deleted IS NULL ORDER BY c.id",
            [(int) $email->getId()]
        );

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'published' => (bool) $r['is_published']], $rows);
    }
}
