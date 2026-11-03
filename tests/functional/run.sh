#!/usr/bin/env bash
# Functional test: MCP server in a real Mautic 7 (with kopexa/mautic-oauth-bundle).
#   tests/functional/run.sh    (see tests/functional/lib.sh for the environment)
# shellcheck source=tests/functional/lib.sh
source "$(dirname "$0")/lib.sh"

install_mautic
configure_oauth
start_server
login >/dev/null

echo "MCP server"
prm="$(curl -s "$BASE/.well-known/oauth-protected-resource/mcp")"
jq -e --arg b "$BASE" '.resource == ($b + "/mcp") and .authorization_servers == [$b]' <<<"$prm" >/dev/null && pass "protected resource metadata" || { echo "$prm"; fail "PRM"; }
[[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" -H 'Content-Type: application/json' -d '{}')" == 401 ]] && pass "no token: 401" || fail "unauthenticated request"

write="$(access_token 'mcp:read mcp:write')"
live="$(access_token 'mcp:read mcp:write mcp:campaigns:live' mcp:campaigns:live)"
mcp_init "$write"
jq -e '.result.serverInfo.name == "Mautic"' "$WORK/init" >/dev/null && [[ -n "$MCP_SESSION" ]] && pass "initialize" || { cat "$WORK/init"; fail "initialize"; }
n="$(mcp_post "$write" '{"jsonrpc":"2.0","id":3,"method":"tools/list"}' | jq '.result.tools | length')"
[[ "$n" -ge 18 ]] && pass "tools/list: $n tools" || fail "tools/list ($n)"

email="$(tool "$write" create_email_draft '{"name":"Functional","subject":"Hallo","html":"<html><body><p>Hi</p></body></html>","type":"template"}')"
eid="$(jq -r .id <<<"$email")"
[[ "$(jq -r .published <<<"$email")" == false ]] && pass "create_email_draft (unpublished)" || { echo "$email"; fail "email draft"; }
api -X PATCH "$BASE/api/emails/$eid/edit" -d isPublished=1 >/dev/null
sid="$(api -X POST "$BASE/api/segments/new" -d name=Functional -d isPublished=1 | jq -r .list.id)"
camp="$(tool "$write" create_campaign_draft "{\"name\":\"Functional\",\"segmentIds\":[$sid]}" | jq -r .id)"
flow='[{"key":"welcome","type":"email.send","properties":{"email":'"$eid"',"email_type":"marketing"}},
       {"key":"opened","type":"email.open","parent":"welcome"},
       {"key":"tag","type":"lead.changetags","parent":"opened","path":"yes","properties":{"add_tags":["engaged"]}},
       {"key":"reminder","type":"email.send","parent":"opened","path":"no","wait":{"interval":3,"unit":"d"},"properties":{"email":'"$eid"',"email_type":"marketing"}}]'
saved="$(tool "$write" set_campaign_steps "{\"id\":$camp,\"steps\":$(jq -c . <<<"$flow")}")"
jq -e '(.steps | length == 4) and ([.steps[] | select(.type == "lead.changetags")][0].properties.add_tags == ["engaged"])' <<<"$saved" >/dev/null \
  && pass "set_campaign_steps: flow with decision, branches, wait, tag by name" || { echo "$saved" | head -c 1500; fail "set_campaign_steps"; }
again="$(tool "$write" set_campaign_steps "{\"id\":$camp,\"steps\":$(jq -c '[.steps[] | del(.kind, .contacts)]' <<<"$saved"),\"dryRun\":true}")"
jq -e '.changes.unchanged | length == 4' <<<"$again" >/dev/null && pass "round trip is a no-op" || { echo "$again" | head -c 800; fail "round trip"; }

api -X PATCH "$BASE/api/campaigns/$camp/edit" -d isPublished=1 >/dev/null
reminder="$(jq -r '.steps[] | select(.path == "no") | .key' <<<"$saved")"
blocked="$(tool "$write" update_campaign_step "{\"campaignId\":$camp,\"key\":\"$reminder\",\"wait\":{\"interval\":5,\"unit\":\"d\"}}")"
[[ "$blocked" == "ERROR: "*"mcp:campaigns:live"* ]] && pass "running campaign locked without mcp:campaigns:live" || { echo "$blocked"; fail "lock"; }
mcp_init "$live"
ok="$(tool "$live" update_campaign_step "{\"campaignId\":$camp,\"key\":\"$reminder\",\"wait\":{\"interval\":5,\"unit\":\"d\"}}")"
jq -e --arg k "$reminder" '([.steps[] | select(.key == $k) | .wait] == [{"interval": 5, "unit": "d"}]) and .live_effects.save_allowed == true' <<<"$ok" >/dev/null \
  && pass "with the scope: running campaign edited in place (live_effects)" || { echo "$ok" | head -c 800; fail "live edit"; }

echo "contacts and segments"
contacts="$(access_token 'mcp:read mcp:contacts:write')"
mcp_init "$contacts"
seg="$(tool "$contacts" create_segment '{"name":"From CRM"}')"; csid="$(jq -r .id <<<"$seg")"
dry="$(tool "$contacts" upsert_contacts "{\"contacts\":[{\"email\":\"ada@example.com\",\"fields\":{\"firstname\":\"Ada\",\"company\":\"ACME\"},\"tags\":[\"crm\"]}],\"segmentIds\":[$sid],\"dryRun\":true}")"
jq -e --argjson c "$camp" '.counts.created == 1 and ([.live_effects.running_campaigns[].id] | index($c) != null)' <<<"$dry" >/dev/null \
  && pass "upsert_contacts dryRun reports the running campaign of the segment" || { echo "$dry" | head -c 800; fail "upsert dryRun"; }
up="$(tool "$contacts" upsert_contacts "{\"contacts\":[{\"email\":\"ada@example.com\",\"fields\":{\"firstname\":\"Ada\",\"company\":\"ACME\"},\"tags\":[\"crm\"]}],\"segmentIds\":[$csid]}")"
again="$(tool "$contacts" upsert_contacts "{\"contacts\":[{\"email\":\"ada@example.com\",\"fields\":{\"company\":\"ACME\"}}]}")"
[[ "$(jq -r '.counts.created' <<<"$up")/$(jq -r '.counts.unchanged' <<<"$again")" == 1/1 ]] \
  && [[ "$(api "$BASE/api/contacts?search=segment:$(jq -r .alias <<<"$seg")" | jq -r .total)" == 1 ]] \
  && pass "upsert_contacts creates, is idempotent, adds to the segment" || { echo "$up $again" | head -c 800; fail "upsert"; }
[[ "$(tool "$write" upsert_contacts '{"contacts":[{"email":"x@example.com"}]}')" != *counts* ]] && pass "contact tools need mcp:contacts:write" || fail "contacts scope"

echo "fields, companies, dynamic segments"
[[ "$(tool "$contacts" create_contact_field '{"label":"CRM ID","alias":"crm_id"}' | jq -r .created)" == true ]] && pass "create_contact_field" || fail "create_contact_field"
co="$(tool "$contacts" upsert_companies '{"companies":[{"fields":{"companyname":"Functional Holding"}}]}')"
[[ "$(jq -r .counts.created <<<"$co")" == 1 ]] && pass "upsert_companies" || { echo "$co"; fail "upsert_companies"; }
dyn="$(tool "$contacts" create_segment '{"name":"ACME people","filters":[{"field":"company","operator":"=","value":"ACME"}],"dryRun":true}')"
jq -e '.dry_run == true and (.matching_contacts | type == "number")' <<<"$dyn" >/dev/null && pass "dynamic segment dry run with count" || { echo "$dyn"; fail "dynamic segment"; }

echo "lead scoring"
mcp_init "$write"
gid="$(tool "$write" create_point_group '{"name":"Interest"}' | jq -r .id)"
[[ "$(tool "$write" create_point_action '{"name":"x","type":"url.hit","delta":5,"properties":{}}')" == "ERROR: "*"page_url"* ]] \
  && [[ "$(tool "$write" create_point_action "{\"name\":\"Pricing\",\"type\":\"url.hit\",\"delta\":20,\"groupId\":$gid,\"properties\":{\"page_url\":\"https://example.com/pricing*\"}}" | jq -r .published)" == false ]] \
  && pass "create_point_action: unpublished, required settings enforced" || fail "point action"
trig="$(tool "$write" create_point_trigger "{\"name\":\"Hot\",\"points\":50,\"groupId\":$gid,\"events\":[{\"type\":\"lead.changetags\",\"name\":\"tag\",\"properties\":{\"add_tags\":[\"hot\"]}}]}")"
tid="$(jq -r .id <<<"$trig")"
[[ "$(jq -r .published <<<"$trig")" == false ]] && pass "create_point_trigger (unpublished)" || { echo "$trig"; fail "point trigger"; }
api -X PATCH "$BASE/api/points/triggers/$tid/edit" -d isPublished=1 >/dev/null
scorer="$(access_token 'mcp:read mcp:write mcp:contacts:write')"
mcp_init "$scorer"
lead="$(api -X POST "$BASE/api/contacts/new" -d email=hot@example.com | jq -r .contact.id)"
adj="$(tool "$scorer" adjust_contact_points "{\"contactId\":$lead,\"points\":60,\"groupId\":$gid}")"
jq -e --argjson t "$tid" '.points.after == 60 and ([.triggers[].id] == [$t])' <<<"$adj" >/dev/null \
  && [[ "$(api "$BASE/api/contacts/$lead" | jq -r '[.contact.tags[].tag] | index("hot") != null')" == true ]] \
  && pass "adjust_contact_points: Mautic fires the published trigger" || { echo "$adj"; fail "adjust points"; }
[[ "$(tool "$scorer" update_point_trigger "{\"id\":$tid,\"points\":40}")" == "ERROR: "*"mcp:campaigns:live"* ]] \
  && pass "published trigger locked without mcp:campaigns:live" || fail "trigger lock"

echo "stages and projects"
st="$(tool "$scorer" create_stage '{"name":"Customer","weight":40}')"; stid="$(jq -r .id <<<"$st")"
[[ "$(tool "$scorer" set_contact_stage "{\"contactId\":$lead,\"stage\":\"Customer\"}")" == "ERROR: "*"not published"* ]] \
  && [[ "$(tool "$scorer" create_stage '{"name":"Other","weight":40}')" == "ERROR: "*"weight 40"* ]] \
  && pass "create_stage: unpublished, unique weight" || { echo "$st"; fail "create_stage"; }
api -X PATCH "$BASE/api/stages/$stid/edit" -d isPublished=1 >/dev/null
tool "$scorer" set_contact_stage "{\"contactId\":$lead,\"stage\":\"Customer\"}" >/dev/null
[[ "$(api "$BASE/api/contacts/$lead" | jq -r .contact.stage.name)" == Customer ]] && pass "set_contact_stage" || fail "set_contact_stage"
prid="$(tool "$scorer" create_project '{"name":"Functional"}' | jq -r .id)"
tool "$scorer" add_to_project "{\"projectId\":$prid,\"type\":\"stage\",\"ids\":[$stid]}" >/dev/null
[[ "$(tool "$scorer" get_project "{\"id\":$prid}" | jq -r '.items.stage[0].name')" == Customer ]] && pass "projects: add item, get_project" || fail "projects"
echo "all MCP checks passed"
