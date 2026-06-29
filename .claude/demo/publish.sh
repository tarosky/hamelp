#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JSON="${DIR}/jovian-faqs.json"
API="${DEMO_URL%/}/wp-json"
AUTH="${DEMO_USER}:${DEMO_APP_PASSWORD}"

echo "== Creating faq_cat terms =="
declare -A CATID
while IFS= read -r cat; do
	resp=$(curl -fsS -u "${AUTH}" -X POST -H "Content-Type: application/json" \
		-d "$(jq -nc --arg n "$cat" '{name:$n}')" "${API}/wp/v2/faq_cat" 2>/dev/null || true)
	id=$(jq -r '.id // .data.term_id // empty' <<<"$resp")
	if [[ -z "$id" ]]; then
		# Already exists or other error: look it up by exact name.
		id=$(curl -fsS -u "${AUTH}" "${API}/wp/v2/faq_cat?per_page=100&search=$(jq -rn --arg s "$cat" '$s|@uri')" \
			| jq -r --arg n "$cat" 'map(select(.name==$n))[0].id // empty')
	fi
	[[ -n "$id" ]] || { echo "FAILED to create/find category: $cat" >&2; exit 1; }
	CATID["$cat"]="$id"
	printf '  %-20s -> %s\n' "$cat" "$id"
done < <(jq -r '[.[].category]|unique[]' "$JSON")

echo "== Creating FAQ posts =="
n=0
while IFS= read -r row; do
	cat=$(jq -r '.category' <<<"$row")
	cid="${CATID[$cat]}"
	body=$(jq -c --argjson cid "$cid" '{title:.title, content:.content, status:"publish", faq_cat:[$cid]}' <<<"$row")
	resp=$(curl -fsS -u "${AUTH}" -X POST -H "Content-Type: application/json" -d "$body" "${API}/wp/v2/faq")
	pid=$(jq -r '.id' <<<"$resp")
	link=$(jq -r '.link' <<<"$resp")
	n=$((n+1))
	printf '  [%2d] #%s  %s\n' "$n" "$pid" "$link"
done < <(jq -c '.[]' "$JSON")

echo "== Done: ${n} posts =="
