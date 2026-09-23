#!/usr/bin/env bash

set -Eeuo pipefail

readonly deploy_host=${PRODUCTION_DEPLOY_HOST:-110.239.91.57}
readonly deploy_port=${PRODUCTION_DEPLOY_PORT:-9022}
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly script_dir
readonly known_hosts_file="$script_dir/known_hosts.example"

if (( $# > 1 )); then
  echo "Usage: $0 [trusted-sha256-fingerprint]" >&2
  exit 64
fi

for required_command in ssh-keygen ssh-keyscan; do
  if ! command -v "$required_command" >/dev/null 2>&1; then
    echo "Missing required command: $required_command" >&2
    exit 69
  fi
done

expected_fingerprint=${1:-}
if [[ -z $expected_fingerprint ]]; then
  if [[ ! -s $known_hosts_file ]]; then
    echo "No existing host key is available for verification." >&2
    echo "Pass the trusted SHA256 fingerprint as the first argument." >&2
    exit 66
  fi

  expected_fingerprint=$(ssh-keygen -E sha256 -lf "$known_hosts_file" | awk 'NR == 1 { print $2 }')
fi

if [[ $expected_fingerprint != SHA256:* ]]; then
  echo "Expected a SHA256 fingerprint, got: $expected_fingerprint" >&2
  exit 65
fi

temporary_file=$(mktemp "$script_dir/.known_hosts.example.XXXXXX")
cleanup() {
  rm -f -- "$temporary_file"
}
trap cleanup EXIT

if ! scanned_key=$(
  ssh-keyscan \
    -T 10 \
    -p "$deploy_port" \
    -t ed25519 \
    "$deploy_host" 2>/dev/null |
    awk '$2 == "ssh-ed25519" { print; exit }'
); then
  echo "Could not scan the SSH host key from $deploy_host:$deploy_port." >&2
  exit 69
fi

if [[ -z $scanned_key ]]; then
  echo "Could not read the ED25519 host key from $deploy_host:$deploy_port." >&2
  exit 69
fi

printf '%s\n' "$scanned_key" >"$temporary_file"
scanned_fingerprint=$(ssh-keygen -E sha256 -lf "$temporary_file" | awk 'NR == 1 { print $2 }')

if [[ $scanned_fingerprint != "$expected_fingerprint" ]]; then
  echo "Refusing to replace $known_hosts_file: SSH fingerprint mismatch." >&2
  echo "Expected: $expected_fingerprint" >&2
  echo "Scanned:  $scanned_fingerprint" >&2
  exit 1
fi

chmod 0644 "$temporary_file"
mv -f -- "$temporary_file" "$known_hosts_file"

echo "Updated $known_hosts_file"
echo "Host:        $deploy_host:$deploy_port"
echo "Fingerprint: $scanned_fingerprint"
echo "Review and commit this file so the GitLab deployment job can use it."
