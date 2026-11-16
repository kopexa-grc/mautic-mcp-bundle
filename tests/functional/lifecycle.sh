# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Publishing, unpublishing and deleting emails: opt-in scopes, dry runs with
# their effects, and the refusals that keep campaigns intact. Sourced by
# run.sh (uses tool, call, api, db, pass, fail, BASE, access).

echo "MCP email publish and delete"
# The consent page groups the scopes; the new ones are opt-in.
client="$(oauth_client)"
verifier="$(openssl rand -base64 48 | tr '+/' '-_' | tr -d '=\n')"
authorize "$client" "$(printf '%s' "$verifier" | openssl dgst -sha256 -binary | b64url)" S256 'mcp:read mcp:write mcp:emails:publish mcp:emails:delete' >/dev/null
grep -q 'value="mcp:emails:publish">' "$WORK/page" && grep -q 'value="mcp:emails:delete">' "$WORK/page" \
  && pass "consent page: publish and delete are opt-in (unticked)" || { grep -o 'value="mcp:[^"]*"[^>]*>' "$WORK/page"; fail "consent opt-in"; }
# Groups need kopexa/mautic-oauth-bundle 0.5+.
if grep -qs 'string $group' "$MAUTIC_DIR"/docroot/plugins/KopexaOAuthBundle/Scope/ScopeDefinition.php; then
  grep -q '<h2>Read</h2>' "$WORK/page" && grep -q '<h2>Edit</h2>' "$WORK/page" && grep -q '<h2>Live: sends or deletes</h2>' "$WORK/page" \
    && pass "consent page: scopes grouped (Read, Edit, Live: sends or deletes)" || { grep -o '<h2>[^<]*</h2>' "$WORK/page"; fail "consent groups"; }
fi

seg="$(api -X POST "$BASE/api/segments/new" -d name='Publish test' -d isPublished=1 | jq -r .list.id)"
for n in 1 2; do
  c="$(api -X POST "$BASE/api/contacts/new" -d "email=publish$n@example.com" | jq -r .contact.id)"
  api -X POST "$BASE/api/segments/$seg/contact/$c/add" >/dev/null
done
html='<html><body><p>Hallo</p><a href="{unsubscribe_url}">Abmelden</a></body></html>'
seg_email="$(tool "$access" create_email_draft "$(jq -nc --arg h "$html" --argjson s "$seg" '{name: "Publish test", subject: "x", html: $h, type: "segment", segmentIds: [$s], topic: ""}')" | jq -r .id)"
no_unsub="$(tool "$access" create_email_draft "$(jq -nc --argjson s "$seg" '{name: "No unsubscribe", subject: "x", html: "<p>x</p>", type: "segment", segmentIds: [$s], topic: ""}')" | jq -r .id)"

denied="$(call "$access" publish_email "{\"id\":$seg_email}")"
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*scope="[^"]*mcp:emails:publish' "$WORK/mcp-headers" \
  && [[ "$(api "$BASE/api/emails/$seg_email" | jq -r .email.isPublished)" == false ]] \
  && pass "publish_email needs the opt-in scope mcp:emails:publish (403, step-up names it)" || { cat "$WORK/mcp-headers"; fail "publish without scope"; }

pub_token="$(access_token 'mcp:read mcp:write mcp:emails:publish mcp:emails:delete' 'mcp:emails:publish mcp:emails:delete')"
dry="$(tool "$pub_token" publish_email "{\"id\":$seg_email,\"dryRun\":true}")"
jq -e '.dry_run == true and .type == "segment" and .live_effects.sends == true and .live_effects.recipients == 2 and (.live_effects.note | test("SENDS"))' <<<"$dry" >/dev/null \
  && [[ "$(api "$BASE/api/emails/$seg_email" | jq -r .email.isPublished)" == false ]] \
  && pass "dry run: segment email would be SENT to 2 contacts; nothing changed" || { echo "$dry"; fail "publish dry run"; }
[[ "$(tool "$pub_token" publish_email "{\"id\":$no_unsub}")" == "ERROR: The segment email has no unsubscribe link"* ]] \
  && pass "segment emails without an unsubscribe link are not published" || fail "published without unsubscribe link"
pub="$(tool "$pub_token" publish_email "{\"id\":$seg_email,\"publishUp\":\"2030-01-01T09:00:00+01:00\"}")"
jq -e '.published == true and .live_effects.when == "2030-01-01T09:00:00+01:00"' <<<"$pub" >/dev/null \
  && [[ "$(api "$BASE/api/emails/$seg_email" | jq -r .email.isPublished)" == true ]] && [[ "$(api "$BASE/api/emails/$seg_email" | jq -r .email.publishUp)" == 2030-01-01* ]] \
  && pass "published, sending starts at publishUp" || { echo "$pub"; fail "publish"; }

[[ "$(tool "$pub_token" delete_email "{\"id\":$seg_email}")" == "ERROR: The email is published. Unpublish it first"* ]] \
  && pass "published emails are not deleted" || fail "published email deleted"
jq -e '.published == false' <<<"$(tool "$pub_token" publish_email "{\"id\":$seg_email,\"publish\":false}")" >/dev/null \
  && [[ "$(api "$BASE/api/emails/$seg_email" | jq -r .email.isPublished)" == false ]] \
  && pass "unpublished (publish: false)" || fail "unpublish"

# A template email in a campaign step cannot be deleted.
in_campaign="$(tool "$access" create_email_draft '{"name":"In campaign","subject":"x","html":"<p>x</p>","topic":""}' | jq -r .id)"
camp="$(tool "$access" create_campaign_draft "{\"name\":\"Delete guard\",\"segmentIds\":[$seg]}" | jq -r .id)"
tool "$access" set_campaign_steps "$(jq -nc --argjson c "$camp" --argjson e "$in_campaign" '{id: $c, steps: [{key: "mail", type: "email.send", properties: {email: $e, email_type: "marketing"}}]}')" >/dev/null
[[ "$(tool "$pub_token" delete_email "{\"id\":$in_campaign}")" == "ERROR: Campaigns use this email"*"Delete guard"* ]] \
  && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $in_campaign")" == 1 ]] \
  && pass "emails used by a campaign are not deleted (campaign named)" || fail "email in campaign deleted"
denied="$(call "$access" delete_email "{\"id\":$no_unsub}")"
grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*scope="[^"]*mcp:emails:delete' "$WORK/mcp-headers" \
  && pass "delete_email needs the opt-in scope mcp:emails:delete" || { cat "$WORK/mcp-headers"; fail "delete without scope"; }
jq -e '.dry_run == true and .lost.sent == 0' <<<"$(tool "$pub_token" delete_email "{\"id\":$no_unsub,\"dryRun\":true}")" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $no_unsub")" == 1 ]] \
  && jq -e '.message == "Deleted."' <<<"$(tool "$pub_token" delete_email "{\"id\":$no_unsub}")" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $no_unsub")" == 0 ]] \
  && pass "delete: dry run shows what is lost, then the unpublished email is deleted" || fail "delete"
