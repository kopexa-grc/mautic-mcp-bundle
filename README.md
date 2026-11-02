# kopexa/mautic-mcp-bundle

[![Packagist](https://img.shields.io/packagist/v/kopexa/mautic-mcp-bundle)](https://packagist.org/packages/kopexa/mautic-mcp-bundle) [![ci](https://github.com/kopexa-grc/mautic-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/kopexa-grc/mautic-mcp-bundle/actions/workflows/ci.yml)

[Model Context Protocol](https://modelcontextprotocol.io) server for
Mautic 7 at `/mcp` (Streamable HTTP). It is built on the official
[MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk).

## Installation

Mautic 7 (Composer-based installation):

```bash
composer require kopexa/mautic-mcp-bundle
php bin/console mautic:plugins:reload
```

Requires `kopexa/mautic-oauth-bundle` (installed automatically by Composer) with a signing key configured, see its README.

Already included in the hardened Mautic image
[`ghcr.io/kopexa-grc/mautic`](https://github.com/kopexa-grc/mautic-stack).


Claude, ChatGPT and other MCP clients can:

- read contacts, segments, emails, campaigns and email statistics
- create **unpublished** emails and campaigns
- build and edit complete campaign flows (actions, decisions, conditions,
  waits). This also works on **running** campaigns, where changes are live:
  Mautic's scheduler executes them and may send emails. Publishing and
  direct sending are not possible.

Every call runs with the Mautic permissions of the user who approved the
client. OAuth 2.1 comes from
[kopexa/mautic-oauth-bundle](https://github.com/kopexa-grc/mautic-oauth-bundle).

Setup, tools and permission model: [docs/mcp.md](https://github.com/kopexa-grc/mautic-stack/blob/main/docs/mcp.md).


## Development

- `bin/check.sh` checks the package (PHP lint, `composer validate`, version
  consistency). CI runs it on every push and pull request.
- Integration tests run in
  [kopexa-grc/mautic-stack](https://github.com/kopexa-grc/mautic-stack): its
  smoke test installs this package into the Mautic image and exercises it
  end to end.
- **Commits** follow [Conventional Commits](https://www.conventionalcommits.org)
  (`feat:`, `fix:`, `docs:`, `chore:` …); they drive versions and the changelog.
- **Releases** are automated with
  [release-please](https://github.com/googleapis/release-please): it keeps a
  release PR open that bumps `VERSION`, the plugin version and `McpServerFactory::VERSION` and
  `CHANGELOG.md`. Merging it tags `v<version>` and creates the GitHub
  release. Packagist and mautic-stack's Renovate pick the tag up.
- **Dependencies** are kept current by Renovate. Composer constraint updates
  are committed as `fix(deps):` and so lead to a patch release; CI-only
  updates (`ci:`) do not.

Apache-2.0 · [Kopexa](https://kopexa.com)
