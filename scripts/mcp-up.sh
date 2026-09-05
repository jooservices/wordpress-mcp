#!/usr/bin/env bash
# Interactive MCP production start: ask for WordPress URL + token per site (no JSON).
# Optional publish: local | https (Caddy) | tunnel (ngrok).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

ENV_FILE="${ENV_FILE:-.env.prod}"
COMPOSE="${DOCKER_COMPOSE:-docker compose}"
COMPOSE_FILE="docker-compose.prod.yml"

if [[ ! -t 0 ]] && [[ ! -r /dev/tty ]]; then
  echo "mcp-up needs an interactive terminal (or pass answers via /dev/tty)." >&2
  exit 1
fi

ask() {
  local prompt="$1"
  local default="${2:-}"
  local reply=""
  if [[ -n "$default" ]]; then
    printf "%s [%s]: " "$prompt" "$default" >/dev/tty
  else
    printf "%s: " "$prompt" >/dev/tty
  fi
  IFS= read -r reply </dev/tty || true
  if [[ -z "$reply" ]]; then
    reply="$default"
  fi
  printf '%s' "$reply"
}

ask_secret() {
  local prompt="$1"
  local reply=""
  printf "%s: " "$prompt" >/dev/tty
  # shellcheck disable=SC2162
  IFS= read -rs reply </dev/tty || true
  printf '\n' >/dev/tty
  printf '%s' "$reply"
}

ask_yes_no() {
  local prompt="$1"
  local default="${2:-n}"
  local hint="y/N"
  [[ "$default" == "y" || "$default" == "Y" ]] && hint="Y/n"
  local reply
  reply="$(ask "$prompt ($hint)" "")"
  if [[ -z "$reply" ]]; then
    reply="$default"
  fi
  case "$reply" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

ask_publish_mode() {
  echo >/dev/tty
  echo "How should MCP be reachable?" >/dev/tty
  echo "  1) Local only   — 127.0.0.1:3000 (default)" >/dev/tty
  echo "  2) HTTPS        — Caddy + Let's Encrypt" >/dev/tty
  echo "  3) Tunnel       — ngrok (dev/testing)" >/dev/tty
  local choice
  choice="$(ask "Choose" "1")"
  case "$choice" in
    2|https|HTTPS|caddy|Caddy) printf '%s' "https" ;;
    3|tunnel|ngrok|NGROK) printf '%s' "tunnel" ;;
    *) printf '%s' "local" ;;
  esac
}

compose_up() {
  local mode="$1"
  case "$mode" in
    https)
      $COMPOSE -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile https up -d --build
      ;;
    tunnel)
      $COMPOSE -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile tunnel up -d --build
      ;;
    *)
      $COMPOSE -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --build mcp
      ;;
  esac
}

wait_ngrok_url() {
  local url=""
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
    url="$(
      python3 <<'PY' 2>/dev/null || true
import json, urllib.request
try:
    with urllib.request.urlopen("http://127.0.0.1:4040/api/tunnels", timeout=2) as r:
        data = json.load(r)
    for t in data.get("tunnels", []):
        pub = t.get("public_url") or ""
        if pub.startswith("https://"):
            print(pub.rstrip("/"))
            break
except Exception:
    pass
PY
    )"
    if [[ -n "$url" ]]; then
      printf '%s' "$url"
      return 0
    fi
    sleep 1
  done
  return 1
}

patch_env_public_url() {
  local new_url="$1"
  PUBLIC_URL="$new_url" ENV_PATH="$ENV_FILE" python3 <<'PY'
import os, re
from pathlib import Path
path = Path(os.environ["ENV_PATH"])
text = path.read_text(encoding="utf-8")
url = os.environ["PUBLIC_URL"]
if re.search(r"^MCP_PUBLIC_URL=.*$", text, flags=re.M):
    text = re.sub(r"^MCP_PUBLIC_URL=.*$", f"MCP_PUBLIC_URL={url}", text, count=1, flags=re.M)
else:
    text += f"\nMCP_PUBLIC_URL={url}\n"
path.write_text(text, encoding="utf-8")
PY
}

require_cmd python3
require_cmd docker

reconfigure=1
if [[ -f "$ENV_FILE" ]]; then
  echo "Found existing $ENV_FILE"
  if ! ask_yes_no "Reconfigure WordPress sites and rewrite it?" "y"; then
    reconfigure=0
  fi
fi

sites_json='[]'
public_url="http://localhost:3000"
auth_mode="static"
auth_secret=""
publish_mode="local"
mcp_domain=""
acme_email=""
ngrok_token=""

if [[ "$reconfigure" -eq 1 ]]; then
  echo
  echo "Add WordPress sites (plugin installed + connection token from wp-admin)."
  echo "You will be asked URL and token for each site — no JSON needed."
  echo

  index=1
  while true; do
    echo "--- Site $index ---"
    url="$(ask "WordPress site URL (https://…)")"
    if [[ -z "$url" ]]; then
      echo "URL is required." >&2
      continue
    fi
    case "$url" in
      http://*|https://*) ;;
      *)
        echo "URL must start with http:// or https://" >&2
        continue
        ;;
    esac

    token="$(ask_secret "Connection token")"
    if [[ -z "$token" ]]; then
      echo "Token is required." >&2
      continue
    fi

    default_id="$(
      python3 - "$url" <<'PY'
import sys
from urllib.parse import urlparse
host = urlparse(sys.argv[1]).hostname or "site"
clean = "".join(ch if ch.isalnum() or ch in "-." else "-" for ch in host.lower())
print(clean.strip("-.") or "site")
PY
    )"
    site_id="$(ask "Site id" "$default_id")"
    site_name="$(ask "Site name" "$site_id")"

    sites_json="$(
      SITES_JSON="$sites_json" SITE_ID="$site_id" SITE_NAME="$site_name" SITE_URL="$url" SITE_TOKEN="$token" python3 <<'PY'
import json, os
sites = json.loads(os.environ["SITES_JSON"])
sites.append(
    {
        "id": os.environ["SITE_ID"].strip(),
        "name": os.environ["SITE_NAME"].strip(),
        "url": os.environ["SITE_URL"].strip().rstrip("/"),
        "token": os.environ["SITE_TOKEN"],
    }
)
print(json.dumps(sites, separators=(",", ":")))
PY
    )"

    index=$((index + 1))
    if ask_yes_no "Add another site?" "n"; then
      echo
      continue
    fi
    break
  done

  publish_mode="$(ask_publish_mode)"

  case "$publish_mode" in
    https)
      mcp_domain="$(ask "Public hostname for HTTPS (DNS → this host)")"
      if [[ -z "$mcp_domain" ]]; then
        echo "MCP_DOMAIN is required for HTTPS." >&2
        exit 1
      fi
      mcp_domain="${mcp_domain#https://}"
      mcp_domain="${mcp_domain#http://}"
      mcp_domain="${mcp_domain%%/*}"
      acme_email="$(ask "Let's Encrypt email")"
      if [[ -z "$acme_email" ]]; then
        echo "ACME_EMAIL is required for HTTPS." >&2
        exit 1
      fi
      public_url="https://${mcp_domain}"
      ;;
    tunnel)
      ngrok_token="$(ask_secret "NGROK_AUTHTOKEN")"
      if [[ -z "$ngrok_token" ]]; then
        echo "NGROK_AUTHTOKEN is required for tunnel mode." >&2
        exit 1
      fi
      public_url="$(ask "MCP public URL if known (blank = detect from ngrok after start)" "")"
      public_url="${public_url%/}"
      if [[ -z "$public_url" ]]; then
        public_url="http://localhost:3000"
      fi
      ;;
    *)
      public_url="$(ask "MCP public URL (ChatGPT connector base)" "http://localhost:3000")"
      public_url="${public_url%/}"
      ;;
  esac

  auth_mode="$(ask "MCP auth mode (mixed|oauth|static|none)" "static")"
  if [[ "$auth_mode" == "static" || "$auth_mode" == "mixed" ]]; then
    auth_secret="$(ask "MCP auth secret (blank = generate)" "")"
    if [[ -z "$auth_secret" ]]; then
      auth_secret="$(python3 -c 'import secrets; print(secrets.token_urlsafe(32))')"
      echo "Generated MCP_AUTH_SECRET (saved in $ENV_FILE)."
    fi
  fi

  umask 077
  SITES_JSON="$sites_json" \
  PUBLIC_URL="$public_url" \
  AUTH_MODE="$auth_mode" \
  AUTH_SECRET="$auth_secret" \
  PUBLISH_MODE="$publish_mode" \
  MCP_DOMAIN="$mcp_domain" \
  ACME_EMAIL="$acme_email" \
  NGROK_AUTHTOKEN="$ngrok_token" \
  ENV_PATH="$ENV_FILE" \
  python3 <<'PY'
import json, os
from pathlib import Path

sites = json.loads(os.environ["SITES_JSON"])
path = Path(os.environ["ENV_PATH"])
mode = os.environ["PUBLISH_MODE"]
lines = [
    "# Generated by scripts/mcp-up.sh — do not commit",
    f"MCP_PUBLISH_MODE={mode}",
    f"WORDPRESS_SITES={json.dumps(json.dumps(sites, separators=(',', ':')))}",
    f"MCP_PUBLIC_URL={os.environ['PUBLIC_URL']}",
    f"MCP_AUTH_MODE={os.environ['AUTH_MODE']}",
    f"MCP_AUTH_SECRET={json.dumps(os.environ['AUTH_SECRET'])}",
    "MCP_AUTH_DISABLED=false",
    "MCP_PORT=3000",
    "MCP_HOST=0.0.0.0",
    "MCP_PUBLISH=127.0.0.1:3000:3000",
    "OAUTH_DATA_DIR=/app/data/oauth",
]
if mode == "https":
    lines.append(f"MCP_DOMAIN={os.environ['MCP_DOMAIN']}")
    lines.append(f"ACME_EMAIL={os.environ['ACME_EMAIL']}")
if mode == "tunnel":
    lines.append(f"NGROK_AUTHTOKEN={json.dumps(os.environ['NGROK_AUTHTOKEN'])}")
path.write_text("\n".join(lines) + "\n", encoding="utf-8")
PY

  echo
  echo "Wrote $ENV_FILE"
else
  publish_mode="$(
    python3 - "$ENV_FILE" <<'PY'
import sys
from pathlib import Path
mode = "local"
for line in Path(sys.argv[1]).read_text(encoding="utf-8").splitlines():
    if line.startswith("MCP_PUBLISH_MODE="):
        mode = line.split("=", 1)[1].strip().strip('"')
        break
print(mode)
PY
  )"
  echo "Keeping $ENV_FILE (publish mode: ${publish_mode})"
  publish_mode="$(ask "Publish mode for this start (local|https|tunnel)" "$publish_mode")"
  case "$publish_mode" in
    https|tunnel|local) ;;
    *) publish_mode="local" ;;
  esac
fi

echo "Starting MCP (${publish_mode})…"
compose_up "$publish_mode"

if [[ "$publish_mode" == "tunnel" ]]; then
  echo "Waiting for ngrok public URL…"
  if detected="$(wait_ngrok_url)"; then
    public_url="$detected"
    patch_env_public_url "$public_url"
    echo "Detected ngrok URL → updated MCP_PUBLIC_URL in $ENV_FILE"
    # Recreate mcp so OAuth/public URL picks up the tunnel host.
    $COMPOSE -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --no-deps mcp
  else
    echo "Could not auto-detect ngrok URL. Check http://127.0.0.1:4040 and set MCP_PUBLIC_URL in $ENV_FILE." >&2
    public_url="$(
      python3 - "$ENV_FILE" <<'PY'
import sys
from pathlib import Path
for line in Path(sys.argv[1]).read_text(encoding="utf-8").splitlines():
    if line.startswith("MCP_PUBLIC_URL="):
        print(line.split("=", 1)[1].strip().strip('"'))
        break
PY
    )"
  fi
else
  public_url="$(
    python3 - "$ENV_FILE" <<'PY'
import sys
from pathlib import Path
for line in Path(sys.argv[1]).read_text(encoding="utf-8").splitlines():
    if line.startswith("MCP_PUBLIC_URL="):
        print(line.split("=", 1)[1].strip().strip('"'))
        break
PY
  )"
fi

echo
echo "MCP endpoint: ${public_url}/mcp"
echo "Health:       curl -fsS ${public_url}/health"
echo "Publish:      ${publish_mode}"
if [[ "$publish_mode" == "https" ]]; then
  echo "HTTPS:        Caddy profile (ports 80/443)"
elif [[ "$publish_mode" == "tunnel" ]]; then
  echo "Tunnel UI:    http://127.0.0.1:4040"
fi
echo "Logs:         make prod-logs"
echo "Stop:         make mcp-down"
