#!/usr/bin/env bash
# Prove this tree and the box that runs it are the same code.
#
# "Our private repo is what we run" is only true if something checks it. The
# box runs ~/AiPBX-dev (bind-mounted into the container at /opt/aipbx); this
# script hashes every source file here, hashes the same paths there, and lists
# what differs or exists on only one side.
#
#   scripts/check_box_drift.sh                 # compare with bat-pop-os
#   scripts/check_box_drift.sh user@host       # somewhere else
#   scripts/check_box_drift.sh localhost        # from the box itself, no ssh
#
# Exit 0 = identical. Exit 1 = drift, listed. Exit 2 = could not compare.
#
# Covers web/ (PHP), scripts/, docs/, conf/, asterisk-config/, signaling/ (Python),
# chat/ (Go) and install.sh — everything that ends up running on the box.
# android/ is a shipped artifact, not installed code, so it is out of scope.
#
# Caches and dependencies are skipped: .phpstan-cache, .pytest_cache,
# __pycache__, vendor/, node_modules/, .git/.
set -o pipefail

HOST="${1:-bat-pop-os}"
DIR="${AIPBX_REMOTE_DIR:-AiPBX-dev}"
ROOTS=(web scripts docs conf asterisk-config signaling chat)
# Root-level files that run on the box but are not inside one of the ROOTS.
EXTRAS=(install.sh)

# A checkout may legitimately lack one of these (asterisk-config is generated for
# the container image, not carried in every tree), so a missing root is skipped
# instead of failing the find that follows it.
PRESENT=()
for r in "${ROOTS[@]}"; do [ -d "$r" ] && PRESENT+=("$r"); done
[ ${#PRESENT[@]} -gt 0 ] || { echo "x none of ${ROOTS[*]} exist here"; exit 2; }
ROOTS=("${PRESENT[@]}")

here="$(cd "$(dirname "$0")/.." && pwd)"
cd "$here" || { echo "x cannot enter $here"; exit 2; }

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

find "${ROOTS[@]}" -type f \
    \( -name '*.php' -o -name '*.py' -o -name '*.sh' -o -name '*.md' \
       -o -name '*.js' -o -name '*.conf' -o -name '*.json' -o -name '*.sql' \
       -o -name '*.go' \) \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' \
    -not -path '*/__pycache__/*' -not -path '*/.phpstan-cache/*' \
    -not -path '*/.pytest_cache/*' > "$tmp/files"

for f in "${EXTRAS[@]}"; do
    [ -f "$f" ] && echo "$f" >> "$tmp/files"
done
sort -o "$tmp/files" "$tmp/files"

if [ ! -s "$tmp/files" ]; then
    echo "x no source files found under ${ROOTS[*]} (run from the repo root)"
    exit 2
fi

# Local side. `sha256sum` is coreutils; macOS also has `shasum -a 256`.
if command -v sha256sum >/dev/null 2>&1; then
    xargs -n 100 sha256sum < "$tmp/files" > "$tmp/here.txt" 2>/dev/null
else
    xargs -n 100 shasum -a 256 < "$tmp/files" > "$tmp/here.txt" 2>/dev/null
fi
sort -k2 "$tmp/here.txt" -o "$tmp/here.txt"

# Remote side: the same paths, hashed from the list we already have. Sending
# the list (instead of a remote find) means a cache directory on the box can
# never show up as drift.
# A file that is missing there must show up as "missing", not abort the run,
# so the hashing failure is swallowed and only ssh / cd decide the exit code.
# On the box itself, ssh would need the box to trust its own host key, and the
# tree being compared is the one this script already sits in.
SELF="$(hostname -s 2>/dev/null || hostname)"
if [ "$HOST" = "localhost" ] || [ "$HOST" = "127.0.0.1" ] || [ "$HOST" = "$SELF" ]; then
    if ! ( cd "$HOME/$DIR" && xargs sha256sum 2>/dev/null; exit 0 ) \
            < "$tmp/files" > "$tmp/there.txt"; then
        echo "x cannot read ~/$DIR on this host"
        exit 2
    fi
elif ! ssh -o BatchMode=yes -o ConnectTimeout=8 "$HOST" \
        "cd ~/$DIR || exit 2; xargs sha256sum 2>/dev/null; exit 0" \
        < "$tmp/files" > "$tmp/there.txt"; then
    echo "x cannot read ~/$DIR on $HOST (ssh failed, or the directory is missing)"
    exit 2
fi
sort -k2 "$tmp/there.txt" -o "$tmp/there.txt"

python3 - "$tmp/here.txt" "$tmp/there.txt" "$HOST" <<'PY'
import sys

def load(path):
    out = {}
    for line in open(path, errors="replace"):
        h, _, p = line.rstrip("\n").partition("  ")
        if p:
            out[p] = h
    return out

here, there, host = load(sys.argv[1]), load(sys.argv[2]), sys.argv[3]
differ = sorted(p for p in set(here) & set(there) if here[p] != there[p])
missing = sorted(set(here) - set(there))
extra = sorted(set(there) - set(here))

if not (differ or missing or extra):
    print(f"ok {len(here)} files identical between this tree and {host}")
    sys.exit(0)

print(f"x drift against {host}: {len(differ)} differ, {len(missing)} missing there, {len(extra)} only there")
for label, paths in (("differs", differ), ("missing", missing), ("only there", extra)):
    for p in paths[:40]:
        print(f"  {label:10} {p}")
    if len(paths) > 40:
        print(f"  ... {len(paths) - 40} more {label}")
sys.exit(1)
PY
