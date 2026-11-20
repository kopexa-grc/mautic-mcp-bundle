<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaMcpBundle\Service;

use MauticPlugin\KopexaOAuthBundle\Scope\ScopeDefinition;
use MauticPlugin\KopexaOAuthBundle\Scope\ScopeProviderInterface;

/**
 * The OAuth scopes of the MCP server, declared to the authorization server
 * (kopexa/mautic-oauth-bundle). McpServerFactory maps tools to them.
 */
final class McpScopes implements ScopeProviderInterface
{
    public const READ           = 'mcp:read';
    public const WRITE          = 'mcp:write';
    public const CONTACTS_WRITE = 'mcp:contacts:write';
    public const CAMPAIGNS_LIVE = 'mcp:campaigns:live';
    public const EMAILS_PUBLISH = 'mcp:emails:publish';
    public const EMAILS_DELETE  = 'mcp:emails:delete';

    /** All scopes, in the order of the consent page. */
    public const ALL = [self::READ, self::WRITE, self::CONTACTS_WRITE, self::EMAILS_PUBLISH, self::EMAILS_DELETE, self::CAMPAIGNS_LIVE];

    /** Scopes a client should hold for normal use (all except the opt-in ones). */
    public const REGULAR = [self::READ, self::WRITE, self::CONTACTS_WRITE];

    public function scopes(): iterable
    {
        yield self::scope(self::READ, 'Read contacts, segments, emails, campaigns and statistics', 'Read', default: true);
        yield self::scope(self::WRITE, 'Create and edit unpublished emails and campaigns and their campaign flows', 'Edit');
        yield self::scope(self::CONTACTS_WRITE, 'Create and update contacts, create segments, add contacts to segments and remove them (contacts added to segments can enter running campaigns and segment emails)', 'Edit');
        yield self::scope(self::EMAILS_PUBLISH, 'Publish and unpublish emails, and change published or sent ones: publishing a segment email SENDS it to the segment, changes go to its next recipients', 'Live: sends or deletes', optIn: true);
        yield self::scope(self::EMAILS_DELETE, 'Delete unpublished emails that no campaign uses, with their statistics', 'Live: sends or deletes', optIn: true);
        yield self::scope(self::CAMPAIGNS_LIVE, 'Change RUNNING campaigns: changes are live and Mautic may send emails because of them', 'Live: sends or deletes', optIn: true);
    }

    /** With the consent page group where the OAuth bundle supports it (0.5+). */
    private static function scope(string $name, string $description, string $group, bool $default = false, bool $optIn = false): ScopeDefinition
    {
        return property_exists(ScopeDefinition::class, 'group')
            ? new ScopeDefinition($name, $description, $default, $optIn, $group)
            : new ScopeDefinition($name, $description, $default, $optIn);
    }
}
