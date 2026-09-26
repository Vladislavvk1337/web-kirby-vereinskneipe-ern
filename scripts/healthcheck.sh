#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Health-Check für Docker/Podman (HEALTHCHECK im Dockerfile).
#
#   scripts/healthcheck.sh            # Liveness:  GET /healthz
#   scripts/healthcheck.sh ready      # Readiness: GET /readyz
#
# In Kubernetes fragen die Probes die Endpunkte direkt per HTTP ab
# (k8s/deployment.yaml); dieses Skript ist für docker/podman gedacht.
# ---------------------------------------------------------------------------
set -euo pipefail

port="${APACHE_PORT:-8080}"
path="/healthz"
[[ "${1:-}" == "ready" ]] && path="/readyz"

exec curl --fail --silent --show-error --max-time 4 -o /dev/null "http://127.0.0.1:${port}${path}"
