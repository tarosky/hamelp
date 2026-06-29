#!/usr/bin/env bash
#
# Drive the demo site (https://demo.kunoichiwp.com/pubplafaq/) via the WordPress
# REST API using an Application Password. No SSH, no shell access — just HTTPS
# Basic Auth scoped to one user's capabilities, revocable per token.
#
# Credentials come from the environment (DEMO_URL, DEMO_USER, DEMO_APP_PASSWORD),
# loaded by direnv from .envrc (git-ignored). See .envrc.example.
#
# Usage:
#   bin/demo.sh whoami                         # verify credentials
#   bin/demo.sh faq:list [--per_page 20]       # list FAQ posts
#   bin/demo.sh faq:create --title "Q" --content "A" [--status draft] [--category 12]
#   bin/demo.sh get  /wp/v2/faq                 # raw GET  against the REST API
#   bin/demo.sh post /wp/v2/faq '{"title":"Q"}' # raw POST (JSON body)
#
set -euo pipefail

hint='Run `direnv allow` after copying .envrc.example to .envrc.'
: "${DEMO_URL:?DEMO_URL is not set. ${hint}}"
: "${DEMO_USER:?DEMO_USER is not set. ${hint}}"
: "${DEMO_APP_PASSWORD:?DEMO_APP_PASSWORD is not set. ${hint}}"

API="${DEMO_URL%/}/wp-json"
AUTH="${DEMO_USER}:${DEMO_APP_PASSWORD}"

# Pretty-print JSON if jq is available, otherwise pass through.
pp() { if command -v jq >/dev/null 2>&1; then jq .; else cat; fi; }

api() {
	local method="$1" path="$2" body="${3:-}"
	local url="${API}${path}"
	if [[ -n "${body}" ]]; then
		curl -fsS -u "${AUTH}" -X "${method}" -H "Content-Type: application/json" -d "${body}" "${url}"
	else
		curl -fsS -u "${AUTH}" -X "${method}" "${url}"
	fi
}

# Minimal JSON string escaper (handles quotes, backslashes, newlines).
json_escape() {
	local s="$1"
	s="${s//\\/\\\\}"
	s="${s//\"/\\\"}"
	s="${s//$'\n'/\\n}"
	printf '%s' "${s}"
}

cmd="${1:-}"; shift || true

case "${cmd}" in
	whoami)
		api GET /wp/v2/users/me | pp
		;;

	faq:list)
		query="per_page=10"
		while [[ $# -gt 0 ]]; do
			case "$1" in
				--per_page) query="per_page=$2"; shift 2 ;;
				*) echo "Unknown option: $1" >&2; exit 1 ;;
			esac
		done
		api GET "/wp/v2/faq?${query}&_fields=id,title,status,link" | pp
		;;

	faq:create)
		title="" content="" status="publish" category=""
		while [[ $# -gt 0 ]]; do
			case "$1" in
				--title)    title="$2";    shift 2 ;;
				--content)  content="$2";  shift 2 ;;
				--status)   status="$2";   shift 2 ;;
				--category) category="$2"; shift 2 ;;
				*) echo "Unknown option: $1" >&2; exit 1 ;;
			esac
		done
		[[ -n "${title}" ]] || { echo "Error: --title is required" >&2; exit 1; }
		body="{\"title\":\"$(json_escape "${title}")\",\"content\":\"$(json_escape "${content}")\",\"status\":\"$(json_escape "${status}")\""
		if [[ -n "${category}" ]]; then
			body="${body},\"faq_cat\":[${category}]"
		fi
		body="${body}}"
		api POST /wp/v2/faq "${body}" | pp
		;;

	get)
		[[ $# -ge 1 ]] || { echo "Usage: bin/demo.sh get <path>" >&2; exit 1; }
		api GET "$1" | pp
		;;

	post)
		[[ $# -ge 2 ]] || { echo "Usage: bin/demo.sh post <path> <json>" >&2; exit 1; }
		api POST "$1" "$2" | pp
		;;

	*)
		grep -E '^#( |$)' "${BASH_SOURCE[0]}" | sed -E 's/^# ?//'
		exit 1
		;;
esac
