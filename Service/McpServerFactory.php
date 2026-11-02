<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use MauticPlugin\KopexaMcpBundle\Tools\ContactTools;
use MauticPlugin\KopexaMcpBundle\Tools\ReadTools;
use MauticPlugin\KopexaMcpBundle\Tools\WriteTools;
use MauticPlugin\KopexaOAuthBundle\Service\Settings as OAuthSettings;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Wires the official MCP PHP SDK to Mautic: tools, MCP sessions stored in
 * the Mautic database (works with several web replicas), and OAuth 2.1
 * bearer token validation against this instance's own authorization server
 * (kopexa/mautic-oauth-bundle), offline with its public key.
 */
class McpServerFactory
{
    public const VERSION = '0.4.1'; // x-release-please-version

    /** Tools that need the mcp:write scope. */
    private const WRITE_TOOLS = ['create_email_draft', 'update_email_draft', 'create_campaign_draft', 'update_campaign_draft', 'set_campaign_steps', 'update_campaign_step', 'add_campaign_step', 'remove_campaign_step'];

    /** Tools that need the mcp:contacts:write scope. */
    private const CONTACT_TOOLS = ['upsert_contacts', 'create_segment', 'add_contacts_to_segment', 'remove_contacts_from_segment'];

    public function __construct(
        private readonly OAuthSettings $oauth,
        private readonly ReadTools $read,
        private readonly WriteTools $write,
        private readonly ContactTools $contacts,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function resourceMetadata(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata(
            resource: $this->oauth->resource(),
            authorizationServers: [$this->oauth->issuer()],
            scopesSupported: [McpScopes::READ, McpScopes::WRITE, McpScopes::CONTACTS_WRITE, McpScopes::CAMPAIGNS_LIVE],
            resourceName: 'Mautic',
            resourceDocumentation: 'https://github.com/kopexa-grc/mautic-stack/blob/main/docs/mcp.md',
        );
    }

    public function transport(ServerRequestInterface $request): StreamableHttpTransport
    {
        $factory  = new HttpFactory();
        $metadata = $this->resourceMetadata();
        $host     = (string) parse_url($this->oauth->issuer(), PHP_URL_HOST);

        $validator = new JwtTokenValidator(
            issuer: $this->oauth->issuer(),
            audience: $this->oauth->resource(),
            keys: [$this->oauth->keyId() => new Key($this->oauth->publicKeyPem(), 'RS256')],
            algorithms: ['RS256'],
            tokenType: 'at+jwt',
            leeway: 30,
        );
        $scopes = new ScopePolicy(
            default: [McpScopes::READ],
            tools: array_fill_keys(self::WRITE_TOOLS, [McpScopes::WRITE]) + array_fill_keys(self::CONTACT_TOOLS, [McpScopes::CONTACTS_WRITE]),
        );

        return new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            $this->logger,
            [
                new CorsMiddleware(),
                // Bearer tokens are never sent by browsers on their own, so
                // any Origin may call; the Host must be this instance.
                new DnsRebindingProtectionMiddleware([$host], ['*'], $factory, $factory),
                new ProtectedResourceMetadataMiddleware($metadata),
                new AuthorizationMiddleware($validator, $metadata, $scopes, $factory, $factory),
            ],
        );
    }

    public function server(): Server
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $cache  = new Psr16Cache(new DoctrineDbalAdapter($this->connection, 'mcp', 0, ['db_table' => $prefix.'kopexa_mcp_sessions']));

        $ro = new ToolAnnotations(readOnlyHint: true, openWorldHint: false);
        $rw = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);

        return Server::builder()
            ->setServerInfo('Mautic', self::VERSION, 'Mautic marketing automation (Kopexa build)', websiteUrl: $this->oauth->issuer(), title: 'Mautic')
            ->setInstructions(implode("\n", [
                'Mautic marketing automation. Read contacts, segments, emails, campaigns and email statistics.',
                'Contacts and segments (scope mcp:contacts:write): upsert_contacts creates or updates contacts matched by email (up to 100 per call; use the field aliases of list_contact_fields), create_segment, add/remove_contacts_to/from_segment. Contacts that join a segment can enter running campaigns and receive published segment emails of it: when segments are involved, call with dryRun first, show the user live_effects and save only after they confirm. Never change contacts the user did not ask for; Do-Not-Contact is never changed.',
                'For exports to other systems, search_contacts with includeFields returns all profile fields; paginate with limit/offset.',
                'Emails and new campaigns are created unpublished; publishing them and sending stay with a person in Mautic.',
                'Exception: flows of RUNNING (published) campaigns can be edited (set_campaign_steps, update/add/remove_campaign_step) if the instance allows it and the user granted the scope mcp:campaigns:live. Such changes are live: Mautic\'s scheduler executes them for the contacts in the campaign and can send emails. Always call with dryRun first, show the user live_effects (outbound steps, contacts in the campaign, save_allowed) and save only after they confirm.',
                'Every call runs with the Mautic permissions of the signed-in user.',
                'Return the "url" of created drafts to the user so they can review them.',
            ]))
            ->setLogger($this->logger)
            ->setSession(new Psr16SessionStore($cache, 'session-', 3600))
            ->addTool([$this->read, 'searchContacts'], 'search_contacts', 'Search contacts', null, $ro)
            ->addTool([$this->read, 'getContact'], 'get_contact', 'Get contact', null, $ro)
            ->addTool([$this->read, 'listSegments'], 'list_segments', 'List segments', null, $ro)
            ->addTool([$this->read, 'listEmails'], 'list_emails', 'List emails', null, $ro)
            ->addTool([$this->read, 'getEmail'], 'get_email', 'Get email', null, $ro)
            ->addTool([$this->read, 'getEmailStats'], 'get_email_stats', 'Email statistics', null, $ro)
            ->addTool([$this->read, 'listCampaigns'], 'list_campaigns', 'List campaigns', null, $ro)
            ->addTool([$this->read, 'getCampaign'], 'get_campaign', 'Get campaign', null, $ro)
            ->addTool([$this->read, 'listCampaignStepTypes'], 'list_campaign_step_types', 'Campaign step types', null, $ro)
            ->addTool([$this->read, 'describeCampaignStepType'], 'describe_campaign_step_type', 'Describe campaign step type', null, $ro)
            ->addTool([$this->contacts, 'listContactFields'], 'list_contact_fields', 'List contact fields', null, $ro)
            ->addTool([$this->contacts, 'upsertContacts'], 'upsert_contacts', 'Create or update contacts', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->contacts, 'createSegment'], 'create_segment', 'Create segment', null, $rw)
            ->addTool([$this->contacts, 'addContactsToSegment'], 'add_contacts_to_segment', 'Add contacts to segment', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->contacts, 'removeContactsFromSegment'], 'remove_contacts_from_segment', 'Remove contacts from segment', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'createEmailDraft'], 'create_email_draft', 'Create email draft', null, $rw)
            ->addTool([$this->write, 'updateEmailDraft'], 'update_email_draft', 'Update email draft', null, $rw)
            ->addTool([$this->write, 'createCampaignDraft'], 'create_campaign_draft', 'Create campaign draft', null, $rw)
            ->addTool([$this->write, 'updateCampaignDraft'], 'update_campaign_draft', 'Update campaign draft', null, $rw)
            ->addTool([$this->write, 'setCampaignSteps'], 'set_campaign_steps', 'Set campaign flow', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'updateCampaignStep'], 'update_campaign_step', 'Update campaign step', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'addCampaignStep'], 'add_campaign_step', 'Add campaign step', null, $rw)
            ->addTool([$this->write, 'removeCampaignStep'], 'remove_campaign_step', 'Remove campaign step', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->build();
    }
}
