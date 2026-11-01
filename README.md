# kopexa/mautic-mcp-bundle

[Model Context Protocol](https://modelcontextprotocol.io) server for
Mautic 7 at `/mcp` (Streamable HTTP). It is built on the official
[MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk).

Claude, ChatGPT and other MCP clients can:

- read contacts, segments, emails, campaigns and email statistics
- create and change **unpublished drafts** of emails and campaigns

Every call runs with the Mautic permissions of the user who approved the
client. OAuth 2.1 comes from
[kopexa/mautic-oauth-bundle](../mautic-oauth-bundle).

Setup, tools and permission model: [docs/mcp.md](../../../docs/mcp.md).

Apache-2.0 · [Kopexa](https://kopexa.com)
