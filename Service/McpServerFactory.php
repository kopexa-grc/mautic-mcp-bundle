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
    public const VERSION = '0.2.0';

    /** Tools that need the mcp:write scope. */
    private const WRITE_TOOLS = ['create_email_draft', 'update_email_draft', 'create_campaign_draft', 'update_campaign_draft', 'set_campaign_steps'];

    public function __construct(
        private readonly OAuthSettings $oauth,
        private readonly ReadTools $read,
        private readonly WriteTools $write,
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
            scopesSupported: array_keys($this->oauth->scopes()),
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
            default: ['mcp:read'],
            tools: array_fill_keys(self::WRITE_TOOLS, ['mcp:write']),
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
                'Write tools only create or change UNPUBLISHED drafts; a person reviews and publishes them in Mautic. Nothing is ever sent from here.',
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
            ->addTool([$this->write, 'createEmailDraft'], 'create_email_draft', 'Create email draft', null, $rw)
            ->addTool([$this->write, 'updateEmailDraft'], 'update_email_draft', 'Update email draft', null, $rw)
            ->addTool([$this->write, 'createCampaignDraft'], 'create_campaign_draft', 'Create campaign draft', null, $rw)
            ->addTool([$this->write, 'updateCampaignDraft'], 'update_campaign_draft', 'Update campaign draft', null, $rw)
            ->addTool([$this->write, 'setCampaignSteps'], 'set_campaign_steps', 'Set campaign steps', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->build();
    }
}
