# kopexa/mautic-mcp-bundle

[Model Context Protocol](https://modelcontextprotocol.io) server for
Mautic 7 at `/mcp` (Streamable HTTP). It is built on the official
[MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk).

## Installation

Mautic 7 (Composer-based installation):

```bash
composer require kopexa/mautic-mcp-bundle
php bin/console mautic:plugins:reload
```

Requires `kopexa/mautic-oauth-bundle` (installed automatically) with a signing key configured, see its README.

Already included in the hardened Mautic image
[`ghcr.io/kopexa-grc/mautic`](https://github.com/kopexa-grc/mautic-stack).

> Development happens in
> [kopexa-grc/mautic-stack](https://github.com/kopexa-grc/mautic-stack/tree/main/image/extensions/mautic-mcp-bundle);
> the package repository is a read-only mirror. Issues and pull requests
> go to mautic-stack.

Claude, ChatGPT and other MCP clients can:

- read contacts, segments, emails, campaigns and email statistics
- create **unpublished** emails and campaigns
- build and edit complete campaign flows (actions, decisions, conditions,
  waits). This also works on **running** campaigns, where changes are live:
  Mautic's scheduler executes them and may send emails. Publishing and
  direct sending are not possible.

Every call runs with the Mautic permissions of the user who approved the
client. OAuth 2.1 comes from
[kopexa/mautic-oauth-bundle](../mautic-oauth-bundle).

Setup, tools and permission model: [docs/mcp.md](../../../docs/mcp.md).

Apache-2.0 · [Kopexa](https://kopexa.com)
