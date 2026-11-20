# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Email topics (Mautic categories): recipients switch them off in their
# email preferences, so every marketing email needs one. Sourced by run.sh
# (uses tool, api, pass, fail, BASE, access).

echo "MCP email topics"
for t in 'Webinare & Events' 'Produkt-Updates'; do api -X POST "$BASE/api/categories/new" --data-urlencode "title=$t" -d bundle=global -d isPublished=1 >/dev/null; done
topics="$(tool "$access" list_email_topics '{}')"
jq -e '[.topics[].name] | index("Webinare & Events") and index("Produkt-Updates")' <<<"$topics" >/dev/null \
  && pass "list_email_topics" || { echo "$topics"; fail "list_email_topics"; }
# (The layout tools share EmailTopics; they need a theme with email layouts, not installed here.)
with="$(tool "$access" create_email_draft '{"name":"Topic","subject":"x","html":"<p>x</p>","topic":"webinare & events"}')"
without="$(tool "$access" create_email_draft '{"name":"No topic","subject":"x","html":"<p>x</p>"}')"
jq -e '.topic == "Webinare & Events" and (has("topic_warning") | not)' <<<"$with" >/dev/null && jq -e '.topic == null and (.topic_warning | test("No topic"))' <<<"$without" >/dev/null \
  && [[ "$(api "$BASE/api/emails/$(jq -r .id <<<"$with")" | jq -r .email.category.title)" == "Webinare & Events" ]] \
  && pass "topic set by name (stored as the email's category); warning without one" || { echo "$with $without" | head -c 800; fail "email topic"; }
nid="$(jq -r .id <<<"$without")"
[[ "$(tool "$access" update_email "{\"id\":$nid,\"topic\":\"Produkt-Updates\"}" | jq -r .topic)" == Produkt-Updates ]] \
  && [[ "$(tool "$access" get_email "{\"id\":$nid,\"includeHtml\":false}" | jq -r .topic)" == Produkt-Updates ]] \
  && [[ "$(tool "$access" update_email "{\"id\":$nid,\"topic\":\"\"}" | jq -r .topic)" == null ]] \
  && pass "update sets and clears the topic; get_email shows it" || fail "update topic"
[[ "$(tool "$access" create_email_draft '{"name":"x","subject":"x","html":"<p>x</p>","topic":"Gibtsnicht"}')" == "ERROR: Unknown topic"*"Produkt-Updates"* ]] \
  && pass "unknown topics are refused with the list of topics" || fail "unknown topic accepted"

echo "MCP update_email: drafts and live (published, sent) emails"
live="$(tool "$access" create_email_draft '{"name":"Live","subject":"Alt","html":"<p>alt</p>","topic":""}' | jq -r .id)"
api -X PATCH "$BASE/api/emails/$live/edit" -d isPublished=1 >/dev/null
db "UPDATE emails SET sent_count = 3 WHERE id = $live" >/dev/null
subject_of() { api "$BASE/api/emails/$1" | jq -r .email.subject; }
topic_of() { api "$BASE/api/emails/$1" | jq -r '.email.category.title // "none"'; }
ren="$(tool "$access" update_email "{\"id\":$live,\"name\":\"Live (renamed)\",\"topic\":\"produkt-updates\"}")"
jq -e '.changed == ["name","topic"] and .topic == "Produkt-Updates" and .live_effects.live == false and .live_effects.already_sent == 3' <<<"$ren" >/dev/null \
  && [[ "$(topic_of "$live")" == Produkt-Updates && "$(api "$BASE/api/emails/$live" | jq -r .email.isPublished)" == true ]] \
  && pass "mcp:write: rename and add a topic to a published, sent email (only narrows; stays published)" || { echo "$ren"; fail "safe changes of a live email"; }
[[ "$(tool "$access" update_email "{\"id\":$live,\"topic\":\"Webinare & Events\"}")" == "ERROR: The email is published: changing topic"*"mcp:emails:publish"* ]] \
  && [[ "$(tool "$access" update_email "{\"id\":$live,\"topic\":\"\"}")" == "ERROR: The email is published"* ]] \
  && [[ "$(tool "$access" update_email "{\"id\":$live,\"subject\":\"Neu\",\"fromName\":\"Kopexa\"}")" == "ERROR: The email is published: changing subject, fromName"* ]] \
  && [[ "$(topic_of "$live")" == Produkt-Updates && "$(subject_of "$live")" == Alt ]] \
  && pass "without mcp:emails:publish: content, sender and changing/removing the topic of a live email are refused" || fail "live email changed without scope"
email_pub="$(access_token 'mcp:read mcp:write mcp:emails:publish' 'mcp:emails:publish')"
all='{"subject":"Neu","html":"<p>neu</p>","plainText":"neu","preheader":"Vorschau","language":"en","fromName":"Kopexa","fromAddress":"news@example.com","replyToAddress":"reply@example.com","topic":"Webinare & Events"}'
dry="$(tool "$email_pub" update_email "$(jq -c --argjson id "$live" '. + {id: $id, dryRun: true}' <<<"$all")")"
jq -e '.dry_run == true and .live_effects.live == true and (.live_effects.live_fields | index("topic") and index("fromAddress")) and (.changed | length) == 9' <<<"$dry" >/dev/null \
  && [[ "$(subject_of "$live")" == Alt ]] \
  && pass "dry run lists all 9 changes and live_effects; nothing changed" || { echo "$dry"; fail "update_email dry run"; }
upd="$(tool "$email_pub" update_email "$(jq -c --argjson id "$live" '. + {id: $id}' <<<"$all")")"
got="$(api "$BASE/api/emails/$live" | jq -c '.email | {subject, customHtml, plainText, preheaderText, language, fromName, fromAddress, replyToAddress, t: .category.title, isPublished}')"
jq -e '.subject == "Neu" and .customHtml == "<p>neu</p>" and .plainText == "neu" and .preheaderText == "Vorschau" and .language == "en" and .fromName == "Kopexa" and .fromAddress == "news@example.com" and .replyToAddress == "reply@example.com" and .t == "Webinare & Events" and .isPublished == true' <<<"$got" >/dev/null \
  && jq -e '.message | test("It is live")' <<<"$upd" >/dev/null \
  && pass "with mcp:emails:publish: all settings of a published, sent email changed" || { echo "$upd $got"; fail "update_email live"; }
clr="$(tool "$email_pub" update_email "{\"id\":$live,\"fromName\":\"\",\"replyToAddress\":\"\",\"topic\":\"\"}")"
[[ "$(api "$BASE/api/emails/$live" | jq -r '[.email.fromName, .email.replyToAddress, .email.category] | map(. // "none") | join(",")')" == none,none,none ]] \
  && pass "\"\" clears optional settings" || { echo "$clr"; fail "update_email clear"; }
[[ "$(tool "$email_pub" update_email "{\"id\":$live,\"fromAddress\":\"kein-mail\"}")" == "ERROR: fromAddress"*"not an email address"* ]] \
  && [[ "$(tool "$email_pub" update_email "{\"id\":$live,\"subject\":\"Neu\"}" | jq -r .message)" == "Nothing to change." ]] \
  && pass "invalid addresses refused; unchanged values are no change" || fail "update_email validation"
call "$read_token" update_email "{\"id\":$live,\"name\":\"x\"}" >/dev/null
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" \
  && pass "update_email needs mcp:write (403)" || { cat "$WORK/mcp-headers"; fail "update_email with read token"; }

echo "MCP emails: \"Mailer is owner\" follows the global setting, like Mautic's editor"
owner_of() { db "SELECT COALESCE(use_owner_as_mailer, 'NULL') FROM emails WHERE id = $1"; }
off_id="$(tool "$access" create_email_draft '{"name":"Owner off","subject":"x","html":"<p>x</p>","topic":""}' | jq -r .id)"
set_params '{"mailer_is_owner": true}'
on_id="$(tool "$access" create_email_draft '{"name":"Owner on","subject":"x","html":"<p>x</p>","topic":""}' | jq -r .id)"
[[ "$(owner_of "$off_id")" == 0 && "$(owner_of "$on_id")" == 1 ]] \
  && pass "new MCP emails take mailer_is_owner (off: 0, on: 1)" || { echo "off=$(owner_of "$off_id") on=$(owner_of "$on_id")"; fail "mailer is owner"; }
set_params '{"mailer_is_owner": false}'
