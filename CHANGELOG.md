# Changelog

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
