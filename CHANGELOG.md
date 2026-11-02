# Changelog

## [0.6.0](https://github.com/kopexa-grc/mautic-mcp-bundle/compare/v0.5.0...v0.6.0) (2026-11-02)


### Features

* CRM and brand email tools: companies, custom fields, dynamic segments, layouts, test email, activity ([1823d13](https://github.com/kopexa-grc/mautic-mcp-bundle/commit/1823d130521a5f0c3c1fe5b4e426ce3d416c0bb7))

## [0.5.0](https://github.com/kopexa-grc/mautic-mcp-bundle/compare/v0.4.1...v0.5.0) (2026-11-02)


### Features

* contacts and segments: upsert_contacts, create_segment, segment membership, list_contact_fields ([ab9ef82](https://github.com/kopexa-grc/mautic-mcp-bundle/commit/ab9ef829b421618c511fc439d7df47705ea739e5))
* declare the mcp:* scopes to the authorization server ([5b5c222](https://github.com/kopexa-grc/mautic-mcp-bundle/commit/5b5c222fc649799eb6bd5eaf0fa598291f5705cb))

## [0.4.1](https://github.com/kopexa-grc/mautic-mcp-bundle/compare/v0.4.0...v0.4.1) (2026-11-02)


### Bug fixes

* require Mautic ^7.2, the versions this plugin is tested with ([5a077bd](https://github.com/kopexa-grc/mautic-mcp-bundle/commit/5a077bd0b289f4bab79ae6eb595b4e57f0e9e9f2))
* require PHP ~8.4, the versions this plugin is tested on ([16697bd](https://github.com/kopexa-grc/mautic-mcp-bundle/commit/16697bd4b0bea09b823147dfdef58db485f85715))

## 0.4.0 – 2026-11-02

- Changes to running campaigns need the scope `mcp:campaigns:live` (granted per client on the consent page, opt-in).
- They also need the instance switch `kopexa_mcp_live_campaign_edits` (Helm `mautic.mcp.liveCampaignEdits`).
- Dry runs report `live_effects.save_allowed` and the reason.
- Requires `kopexa/mautic-oauth-bundle` ^0.2.

## 0.3.2 – 2026-11-02

- Results for running campaigns contain `live_effects`: the outbound steps that were added or changed, and the contacts in the campaign.

## 0.3.1 – 2026-11-02

- Tag fields accept tag names. Missing tags are created, as the builder's tag widget does.
- Fields the builder always posts are filled in: empty multi-selects and interval fields.
- Emails in `email.send.to.user` are checked for permission and template type.

## 0.3.0 – 2026-11-01

- `get_campaign` returns the flow in the `set_campaign_steps` format.
- `set_campaign_steps` applies a diff. Existing steps keep their ID and contact history.
- New tools: `update_campaign_step`, `add_campaign_step`, `remove_campaign_step`, all with `dryRun`.
- Running campaigns:
  - steps can be changed and added
  - steps cannot be moved
  - steps can be removed only with confirmation, optionally redirecting waiting contacts

## 0.2.0 – 2026-11-01

- Complete campaign flows: `list_campaign_step_types`, `describe_campaign_step_type`, `set_campaign_steps`.
- Steps are validated through each step type's own form and follow the builder's connection rules.

## 0.1.0 – 2026-11-01

- MCP server (official PHP SDK, Streamable HTTP) at `/mcp`.
- Tokens are verified against `kopexa/mautic-oauth-bundle`.
- Read tools for contacts, segments, emails, statistics and campaigns.
- Draft tools for emails and campaigns.
- Every call runs with the Mautic role of the approving user.
