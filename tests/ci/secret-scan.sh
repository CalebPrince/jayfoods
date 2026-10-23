#!/usr/bin/env bash
# Fails when a secret, a database or the app key is in the repository (CTL-SEC-001).
# The repository is public, so this runs on every pull request.
set -euo pipefail

fail=0
tracked=$(git ls-files)

for f in $tracked; do
  case "$f" in
    *.sqlite|*.sqlite-*|database/app.key|*.pem|*.p12|.env|*.env) echo "Tracked file must not be in the repository: $f"; fail=1 ;;
  esac
done

patterns=(
  'AIza[0-9A-Za-z_-]{35}'            # Google API key
  'sk-ant-[0-9A-Za-z_-]{20,}'        # Anthropic key
  'gsk_[0-9A-Za-z]{20,}'             # Groq key
  'sk-(proj-)?[0-9A-Za-z_-]{32,}'    # OpenAI / OpenRouter / DeepSeek style key
  'EAA[0-9A-Za-z]{60,}'              # Meta access token
  'sk_live_[0-9A-Za-z]{20,}'         # Paystack live secret
  '-----BEGIN [A-Z ]*PRIVATE KEY-----'
)
for p in "${patterns[@]}"; do
  if git grep -nIE -- "$p" -- . ':!tests/ci/secret-scan.sh' >/tmp/hits 2>/dev/null; then
    echo "Possible secret matching $p:"; cut -c1-160 /tmp/hits; fail=1
  fi
done

if [ "$fail" -ne 0 ]; then echo "Secret scan failed."; exit 1; fi
echo "Secret scan passed."
