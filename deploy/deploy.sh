#!/usr/bin/env bash
# Deploy to Dokploy from a Mac via its API:
# wait for the commit's image in GitHub Actions, sync compose.yml into the Raw stack,
# set API_TAG=sha-<commit>, Deploy, wait for the result. WORKERS_TAG (mytube-workers)
# is left as is.
#
#     deploy/deploy.sh             deploy origin/main
#     deploy/deploy.sh <commit>    pin/roll back to <commit>
#
# Always a sha- tag, never latest: compose doesn't re-pull a tag the host already has.
#
# API key: Dokploy → Settings → Profile → API/CLI → Generate, then once
#     security add-generic-password -U -a "$USER" -s dokploy-api -w
# (or set DOKPLOY_API_KEY).
#
# DOKPLOY_URL (and optionally PROJECT, STACK) come from the environment or from
# deploy/deploy.env (git-ignored), e.g. DOKPLOY_URL=http://dokploy.home.internal
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=/dev/null
[[ -f "${ROOT}/deploy/deploy.env" ]] && source "${ROOT}/deploy/deploy.env"
DOKPLOY_URL="${DOKPLOY_URL:?set DOKPLOY_URL (or put it in deploy/deploy.env)}"
PROJECT="${PROJECT:-mytube}"
STACK="${STACK:-mytube}"
WORKFLOW=image.yml

log() { printf '==> %s\n' "$*"; }
ok()  { printf '  ✓ %s\n' "$*"; }
die() { printf '  ✗ %s\n' "$*" >&2; exit 1; }

KEY="${DOKPLOY_API_KEY:-$(security find-generic-password -s dokploy-api -w 2>/dev/null || true)}"
[[ -n "${KEY}" ]] || die "no Dokploy API key: security add-generic-password -U -a \"\$USER\" -s dokploy-api -w"

api() {  # api GET|POST <procedure> [json]
  local method=$1 proc=$2 body=${3:-}
  local args=(-sS --max-time 30 -H "x-api-key: ${KEY}" -H 'Content-Type: application/json'
              -w '\n%{http_code}' -X "${method}")
  [[ -n "${body}" ]] && args+=(-d "${body}")
  local out code
  out="$(curl "${args[@]}" "${DOKPLOY_URL}/api/${proc}")" || die "Dokploy unreachable (${DOKPLOY_URL})"
  code="${out##*$'\n'}"; out="${out%$'\n'*}"
  [[ "${code}" == 2* ]] || die "${proc}: HTTP ${code}: ${out:0:300}"
  printf '%s' "${out}"
}

# --- Commit and image -------------------------------------------------------------
cd "${ROOT}"
SHA="$(git rev-parse "${1:-HEAD}")"
TAG="sha-${SHA:0:7}"
git fetch -q origin
git branch -r --contains "${SHA}" | grep -q . || die "${SHA:0:7} is not pushed"
[[ -n "${1:-}" || "${SHA}" == "$(git rev-parse origin/main)" ]] \
  || die "HEAD (${SHA:0:7}) is not origin/main. Push to main or pass a commit"

log "Image ${SHA:0:7} (${TAG})"
# A commit can get several runs, and concurrency cancels all but the newest: skip cancelled ones.
built=""
for _ in $(seq 1 12); do
  RUN="$(gh run list --workflow "${WORKFLOW}" --commit "${SHA}" --limit 10 --json databaseId,conclusion \
    -q '[.[] | select(.conclusion != "cancelled")][0].databaseId // empty')"
  if [[ -n "${RUN}" ]]; then
    gh run watch "${RUN}" --exit-status --interval 10 >/dev/null && { built=1; break; }
    [[ "$(gh run view "${RUN}" --json conclusion -q .conclusion)" == cancelled ]] \
      || die "image build failed: gh run view ${RUN} --log-failed"
  fi
  sleep 5
done
[[ -n "${built}" ]] || die "no image build for ${SHA:0:7} in Actions"
ok "built"

# --- Stack ------------------------------------------------------------------------
log "Stack ${PROJECT}/${STACK}"
COMPOSE_ID="$(api GET project.all | jq -r --arg p "${PROJECT}" --arg n "${STACK}" \
  '[.[] | select(.name == $p) | .. | objects | select(has("composeId") and .name == $n) | .composeId] | first // empty')"
[[ -n "${COMPOSE_ID}" ]] || die "stack ${PROJECT}/${STACK} not found"

# Raw stack holds a copy of deploy/compose.yml; the repository is the source of truth.
ENV="$(api GET "compose.one?composeId=${COMPOSE_ID}" | jq -r '.env // ""')"
PREVIOUS="$(sed -n 's/^API_TAG=//p' <<<"${ENV}")"
if grep -q '^API_TAG=' <<<"${ENV}"; then
  ENV="$(sed "s/^API_TAG=.*/API_TAG=${TAG}/" <<<"${ENV}")"
else
  ENV="${ENV:+${ENV}$'\n'}API_TAG=${TAG}"
fi
COMPOSE_FILE="$(mktemp)"; trap 'rm -f "${COMPOSE_FILE}"' EXIT
git show "${SHA}:deploy/compose.yml" > "${COMPOSE_FILE}"
api POST compose.update "$(jq -n --arg id "${COMPOSE_ID}" --arg env "${ENV}" --rawfile file "${COMPOSE_FILE}" \
  '{composeId: $id, sourceType: "raw", composeFile: $file, env: $env}')" >/dev/null
ok "compose.yml synced, API_TAG=${TAG} (was ${PREVIOUS:-unset})"

# --- Deploy -----------------------------------------------------------------------
log "Deploy"
latest() { api GET "deployment.allByCompose?composeId=${COMPOSE_ID}" \
  | jq -r 'sort_by(.createdAt) | last // {} | "\(.deploymentId // "") \(.status // "")"'; }
before="$(latest | cut -d' ' -f1)"
api POST compose.deploy "$(jq -n --arg id "${COMPOSE_ID}" '{composeId: $id}')" >/dev/null
id="" status=""
for _ in $(seq 1 120); do
  sleep 5
  read -r id status <<<"$(latest)"
  [[ "${id}" != "${before}" && ( "${status}" == "done" || "${status}" == error ) ]] && break
done
[[ "${id}" != "${before}" && "${status}" == "done" ]] \
  || die "deploy: ${status:-no response}; see Dokploy → ${PROJECT} → ${STACK} → Deployments"
ok "deployed (${TAG}); roll back: deploy/deploy.sh ${PREVIOUS#sha-}"
