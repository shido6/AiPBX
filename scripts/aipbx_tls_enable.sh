#!/usr/bin/env bash
# Point the AiPBX SIP/TLS transport at a real name and a real certificate.
#
# What it changes, all through the portal's own tables so the next sync keeps it:
#   pjsipsettings.tls_enabled        = 1
#   pjsipsettings.tls_bindaddr       = 0.0.0.0 (only if unset)
#   pjsipsettings.tls_bindport       = 5061    (or --port)
#   pjsipsettings.tls_domain         = <name>  (what a phone registers against)
#   pjsipsettings.tls_cert_file      = the certificate inside the container
#   pjsipsettings.tls_priv_key_file  = its key
# then syncTransports() rewrites /etc/asterisk/pbx/pjsip_transports.conf and
# reloads PJSIP, with the portal's usual rollback if the reload is rejected.
#
# usage, on the PBX host:
#   scripts/aipbx_tls_enable.sh --domain sip-tls.s2insights.cloud
#   scripts/aipbx_tls_enable.sh --domain sip-tls.s2insights.cloud \
#       --cert /etc/letsencrypt/live/sip-tls.s2insights.cloud/fullchain.pem \
#       --key  /etc/letsencrypt/live/sip-tls.s2insights.cloud/privkey.pem
#   scripts/aipbx_tls_enable.sh --publish      # add 5061 to the compose ports
#
# --dry-run prints the plan and changes nothing.
set -euo pipefail

CONTAINER="${AIPBX_CONTAINER:-aipbx-dev}"
DOMAIN=""
PORT="5061"
CERT=""
KEY=""
DRY=0
PUBLISH=0
KEYS_DIR="/etc/asterisk/keys"

say() { printf '%s\n' "$*"; }
die() { printf 'x %s\n' "$*" >&2; exit 1; }

usage() { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="${2:?--domain needs a name}"; shift 2 ;;
        --port) PORT="${2:?--port needs a number}"; shift 2 ;;
        --cert) CERT="${2:?--cert needs a path}"; shift 2 ;;
        --key) KEY="${2:?--key needs a path}"; shift 2 ;;
        --container) CONTAINER="${2:?--container needs a name}"; shift 2 ;;
        --publish) PUBLISH=1; shift ;;
        --dry-run) DRY=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown argument: $1 (try --help)" ;;
    esac
done

[ -n "$DOMAIN" ] || [ "$PUBLISH" = 1 ] || die "give a --domain (or --publish to only open the port)"
command -v docker >/dev/null || die "docker not found: run this on the PBX host"

sql() { docker exec "$CONTAINER" mysql -N -B asterisk -e "$1"; }
setting() {
    local key="$1" value="$2"
    say "  $key = $value"
    [ "$DRY" = 1 ] && return 0
    sql "INSERT INTO pjsipsettings (keyword, data, seq, type) VALUES ('$key', '$value', 1, 0)
         ON DUPLICATE KEY UPDATE data = VALUES(data)" >/dev/null
}

running() { docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; }
running || die "container $CONTAINER is not running"

# --------------------------------------------------------------------------
# certificate: prove the pair matches and covers the name before it is used
# --------------------------------------------------------------------------
if [ -n "$CERT" ] || [ -n "$KEY" ]; then
    [ -n "$CERT" ] && [ -n "$KEY" ] || die "--cert and --key go together"
    [ -r "$CERT" ] || die "cannot read $CERT"
    [ -r "$KEY" ] || die "cannot read $KEY"

    cert_pub="$(openssl x509 -in "$CERT" -noout -pubkey 2>/dev/null)" \
        || die "$CERT is not a PEM certificate"
    key_pub="$(openssl pkey -in "$KEY" -pubout 2>/dev/null)" \
        || die "$KEY is not a readable private key"
    [ "$cert_pub" = "$key_pub" ] || die "the key does not belong to the certificate"

    [ -n "$DOMAIN" ] || die "--domain is needed to check the certificate against the name"
    openssl x509 -in "$CERT" -noout -ext subjectAltName 2>/dev/null \
        | tail -n +2 | tr ',' '\n' | sed 's/^ *//; s/^DNS://' > "/tmp/aipbx-tls-sans.$$"
    matched=0
    while read -r san; do
        [ -n "$san" ] || continue
        if [ "$san" = "$DOMAIN" ]; then matched=1; fi
        case "$san" in
            "*."*) [ "${DOMAIN#*.}" = "${san#*.}" ] && matched=1 ;;
        esac
    done < "/tmp/aipbx-tls-sans.$$"
    rm -f "/tmp/aipbx-tls-sans.$$"
    [ "$matched" = 1 ] || die "the certificate does not cover $DOMAIN (SANs: $(openssl x509 -in "$CERT" -noout -ext subjectAltName | tail -n +2 | tr -d '\n'))"

    say "certificate"
    say "  subject: $(openssl x509 -in "$CERT" -noout -subject | sed 's/^subject=//')"
    say "  expires: $(openssl x509 -in "$CERT" -noout -enddate | sed 's/^notAfter=//')"
    say "  covers:  $DOMAIN"

    cert_base="$(basename "$CERT")"
    key_base="$(basename "$KEY")"
    cert_path="$KEYS_DIR/$cert_base"
    key_path="$KEYS_DIR/$key_base"

    if [ "$DRY" = 1 ]; then
        say "  would install $CERT -> $cert_path and $KEY -> $key_path in $CONTAINER"
    else
        docker exec "$CONTAINER" mkdir -p "$KEYS_DIR"
        docker cp "$CERT" "$CONTAINER:$cert_path"
        docker cp "$KEY" "$CONTAINER:$key_path"
        docker exec "$CONTAINER" chown asterisk:asterisk "$cert_path" "$key_path"
        docker exec "$CONTAINER" chmod 0644 "$cert_path"
        docker exec "$CONTAINER" chmod 0640 "$key_path"
        say "  installed into $CONTAINER:$KEYS_DIR"
    fi
else
    cert_path=""
    key_path=""
fi

# --------------------------------------------------------------------------
# settings
# --------------------------------------------------------------------------
if [ -n "$DOMAIN" ]; then
    say "pjsipsettings"
    setting tls_enabled 1
    if [ -z "$(sql "SELECT data FROM pjsipsettings WHERE keyword='tls_bindaddr'" 2>/dev/null)" ]; then
        setting tls_bindaddr 0.0.0.0
    fi
    setting tls_bindport "$PORT"
    setting tls_domain "$DOMAIN"
    [ -n "$cert_path" ] && setting tls_cert_file "$cert_path"
    [ -n "$key_path" ] && setting tls_priv_key_file "$key_path"

    if [ "$DRY" = 1 ]; then
        say "would regenerate pjsip_transports.conf and reload PJSIP"
    else
        say "regenerating transports"
        docker exec "$CONTAINER" php -r \
            'require "/opt/aipbx/web/src/asterisk_sync.php"; syncTransports();' \
            || die "the sync failed; the portal rolled the transport file back"
        sql "DELETE FROM sys_pending_sync WHERE domain = 'transports'" >/dev/null || true
    fi
fi

# --------------------------------------------------------------------------
# publish the port (a container recreate, so the PBX drops registrations for
# a few seconds; done only when asked, and only if compose says so)
# --------------------------------------------------------------------------
if [ "$PUBLISH" = 1 ]; then
    compose=""
    for candidate in "$HOME/aipbx-docker/docker-compose.yml" ./docker-compose.yml; do
        [ -f "$candidate" ] && compose="$candidate" && break
    done
    [ -n "$compose" ] || die "no docker-compose.yml found (looked in ~/aipbx-docker)"

    if grep -qE '^[[:space:]]*-[[:space:]]*"[0-9]+:'"$PORT" "$compose"; then
        say "port $PORT is already published in $compose"
    else
        say "publishing $PORT/tcp in $compose"
        if [ "$DRY" = 1 ]; then
            say "  would add - \"$PORT:$PORT\" after the existing sip line"
        else
            cp "$compose" "$compose.bak-$(date +%Y%m%d%H%M%S)"
            # keep the SIP lines together, right after the published 5060 one
            python3 - "$compose" "$PORT" <<'PY'
import sys
path, port = sys.argv[1], sys.argv[2]
lines = open(path, encoding='utf-8').read().splitlines(True)
insert_at, indent = None, '      '
for i, line in enumerate(lines):
    stripped = line.strip()
    if stripped.startswith('-') and (':5060' in stripped or ':5061' in stripped):
        insert_at = i + 1
        indent = line[:len(line) - len(line.lstrip())]
if insert_at is None:
    sys.exit('no existing 5060 port line to sit next to')
if f'  - "{port}:{port}"' in ''.join(lines):
    sys.exit(0)
lines.insert(insert_at, f'{indent}- "{port}:{port}"\n')
open(path, 'w', encoding='utf-8').write(''.join(lines))
PY
            say "  added, backup next to it"
            say "  recreating the container (registrations drop for a few seconds)"
            ( cd "$(dirname "$compose")" && docker compose up -d )
        fi
    fi
fi

# --------------------------------------------------------------------------
# what is true now
# --------------------------------------------------------------------------
if [ "$DRY" = 1 ]; then
    exit 0
fi

say
say "transport as Asterisk sees it"
docker exec "$CONTAINER" asterisk -rx "pjsip show transports" 2>/dev/null | grep -E 'tls' || true
say
say "generated stanza"
docker exec "$CONTAINER" sed -n '/^\[transport-tls\]/,/^$/p' /etc/asterisk/pbx/pjsip_transports.conf || true
if [ -n "$DOMAIN" ]; then
    say
    say "next, from a machine that can reach this box:"
    say "  python3 scripts/sip_tls_probe.py --host $DOMAIN --user <extension> --password-file <file>"
fi
