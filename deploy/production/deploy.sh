#!/usr/bin/env bash

set -Eeuo pipefail

if (( $# != 2 )); then
  echo "Usage: deploy.sh <commit-sha> <bundle-dir>" >&2
  exit 64
fi

readonly release_id=$1
readonly bundle_dir=$2
readonly image_repository=delami-shipping-rates-api
readonly app_image="$image_repository:$release_id"
readonly source_dir="$bundle_dir/source"
readonly app_dir=/opt/delami/shipping-rates-api/production
readonly compose_project=delami-shipping-rates-api-production
readonly caddy_target=/etc/caddy/sites-enabled/delami-shipping-rates-api-production.caddy
readonly private_health_url=http://127.0.0.1:15004/health
readonly public_health_url=https://rates.delamibrands.com/health

if (( EUID != 0 )); then
  echo "The production deploy script must run as root." >&2
  exit 77
fi

if [[ ! $release_id =~ ^[0-9a-f]{40,64}$ ]]; then
  echo "Invalid commit SHA." >&2
  exit 65
fi

if [[ ! $bundle_dir =~ ^/tmp/delami-shipping-rates-api-production-[0-9]+-[0-9]+$ ]] || [[ ! -d $bundle_dir ]]; then
  echo "Invalid deployment bundle directory." >&2
  exit 65
fi

for required_command in docker curl caddy systemctl; do
  if ! command -v "$required_command" >/dev/null 2>&1; then
    echo "The deployment host is missing required command: $required_command" >&2
    exit 69
  fi
done
if ! docker compose version >/dev/null 2>&1; then
  echo "The deployment host requires Docker Compose v2." >&2
  exit 69
fi

deployed_release_id=""
stored_previous_release_id=""
if [[ -f $app_dir/release.env ]]; then
  deployed_release_id=$(sed -nE 's/^COMMIT_SHA=([0-9a-f]{40,64})$/\1/p' "$app_dir/release.env" | head -n 1)
  stored_previous_release_id=$(sed -nE 's/^PREVIOUS_COMMIT_SHA=([0-9a-f]{40,64})$/\1/p' "$app_dir/release.env" | head -n 1)
fi

if [[ $deployed_release_id == "$release_id" ]]; then
  previous_release_id=$stored_previous_release_id
else
  previous_release_id=$deployed_release_id
fi
readonly previous_release_id

cleanup() {
  rm -rf -- "$bundle_dir"
}
trap cleanup EXIT

for required_file in \
  source/Dockerfile \
  source/composer.json \
  source/composer.lock \
  source/public/index.php \
  source/spark \
  source/docker/apache-vhost.conf \
  source/docker/prepare-sqlite.php \
  source/deploy/production/compose.yml \
  source/deploy/production/delami-shipping-rates-api-production.caddy \
  stack.env; do
  if [[ ! -f "$bundle_dir/$required_file" ]]; then
    echo "Deployment bundle is missing $required_file." >&2
    exit 66
  fi
done

if [[ ! -s $bundle_dir/stack.env ]]; then
  echo "PRODUCTION_ENV_FILE must not be empty." >&2
  exit 78
fi

env_value() {
  local key=$1
  local value

  value=$(awk -v requested="$key" '
    /^[[:space:]]*(#|$)/ { next }
    {
      separator = index($0, "=")
      if (separator == 0) next
      name = substr($0, 1, separator - 1)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", name)
      if (name == requested) print substr($0, separator + 1)
    }
  ' "$bundle_dir/stack.env" | tail -n 1)

  value=${value%$'\r'}
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"

  if (( ${#value} >= 2 )); then
    if [[ ${value:0:1} == '"' && ${value: -1} == '"' ]] || \
       [[ ${value:0:1} == "'" && ${value: -1} == "'" ]]; then
      value=${value:1:${#value}-2}
    fi
  fi

  printf '%s' "$value"
}

for required_key in \
  CI_ENVIRONMENT \
  app.baseURL \
  app.forceGlobalSecureRequests \
  DB_CONNECTION \
  encryption.key \
  shopify.carrierCallbackToken \
  couriers.proxyBaseUrl; do
  if [[ -z $(env_value "$required_key") ]]; then
    echo "PRODUCTION_ENV_FILE is missing a non-empty ${required_key}." >&2
    exit 78
  fi
done

ci_environment=$(env_value CI_ENVIRONMENT)
if [[ ${ci_environment,,} != production ]]; then
  echo "CI_ENVIRONMENT must be exactly production." >&2
  exit 78
fi

base_url=$(env_value app.baseURL)
if [[ ${base_url%/} != https://rates.delamibrands.com ]]; then
  echo "app.baseURL must be https://rates.delamibrands.com/." >&2
  exit 78
fi

app_force_secure=$(env_value app.forceGlobalSecureRequests)
if [[ ${app_force_secure,,} != true ]]; then
  echo "app.forceGlobalSecureRequests must be exactly true." >&2
  exit 78
fi

cookie_secure=$(env_value cookie.secure)
if [[ ${cookie_secure,,} == false ]]; then
  echo "cookie.secure=false is forbidden in production." >&2
  exit 78
fi

validate_secret() {
  local key=$1
  local minimum_length=$2
  local value value_lower

  value=$(env_value "$key")
  value_lower=${value,,}
  if (( ${#value} < minimum_length )) || \
     [[ $value_lower =~ (change[-_ ]?me|replace[-_ ]?with|placeholder|example|xxxx) ]]; then
    echo "PRODUCTION_ENV_FILE needs a non-placeholder ${key} of at least ${minimum_length} characters." >&2
    exit 78
  fi
}

validate_secret encryption.key 32
validate_secret shopify.carrierCallbackToken 32

database_connection=$(env_value DB_CONNECTION)
if [[ ${database_connection,,} != sqlite ]]; then
  echo "DB_CONNECTION must be exactly sqlite for this deployment." >&2
  exit 78
fi

database_file=$(env_value DB_DATABASE)
if [[ ! $database_file =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || [[ $database_file == . || $database_file == .. ]]; then
  echo "DB_DATABASE must be a bare SQLite filename stored in writable/database/." >&2
  exit 78
fi
if [[ $(env_value database.sqlite.DBDebug) != false ]]; then
  echo "database.sqlite.DBDebug must be exactly false in production." >&2
  exit 78
fi

storefront_origins=$(env_value cors.storefrontOrigins)
storefront_origins=${storefront_origins,,}
if [[ $storefront_origins == *localhost* || \
      $storefront_origins == *127.0.0.1* || \
      $storefront_origins == *example.com* ]]; then
  echo "cors.storefrontOrigins contains a local or placeholder origin." >&2
  exit 78
fi

courier_proxy_url=$(env_value couriers.proxyBaseUrl)
courier_proxy_url_lower=${courier_proxy_url,,}
if [[ ! $courier_proxy_url =~ ^https:// ]] || \
   [[ $courier_proxy_url_lower =~ (change[-_ ]?me|placeholder|example\.com) ]]; then
  echo "couriers.proxyBaseUrl must be the real HTTPS production proxy URL." >&2
  exit 78
fi

echo "Building the CodeIgniter shipping-rates API image for $release_id..."
docker build \
  --pull \
  --tag "$app_image" \
  --label com.delami.project=shipping-rates-api \
  --label com.delami.environment=production \
  --label "org.opencontainers.image.revision=$release_id" \
  "$source_dir"

install -d -m 0755 "$app_dir" /etc/caddy/sites-enabled
install -m 0644 "$source_dir/deploy/production/compose.yml" "$app_dir/compose.yml"
install -m 0600 "$bundle_dir/stack.env" "$app_dir/stack.env"

compose_with_image() {
  local selected_app_image=$1
  shift

  APP_IMAGE="$selected_app_image" \
    docker compose \
      --project-name "$compose_project" \
      --env-file "$app_dir/stack.env" \
      --file "$app_dir/compose.yml" \
      "$@"
}

compose() {
  compose_with_image "$app_image" "$@"
}

rollback_stack() {
  if [[ -z $previous_release_id ]]; then
    echo "No previous production release is available for automatic rollback." >&2
    return 1
  fi

  local previous_image="$image_repository:$previous_release_id"
  if ! docker image inspect "$previous_image" >/dev/null 2>&1; then
    echo "Cannot roll back: previous image $previous_image is unavailable." >&2
    return 1
  fi

  echo "Restoring shipping-rates API release $previous_release_id..." >&2
  compose_with_image "$previous_image" \
    up --detach --remove-orphans --wait --wait-timeout 180 app
}

echo "Applying CodeIgniter database migrations..."
compose run --rm --no-deps migrate

echo "Starting the production shipping-rates API..."
if ! compose up --detach --remove-orphans --wait --wait-timeout 180 app; then
  rollback_stack || true
  echo "The production container failed its health check." >&2
  exit 1
fi

if ! curl --fail --silent --show-error --max-time 10 \
  --header 'X-Forwarded-Proto: https' \
  "$private_health_url" >/dev/null; then
  rollback_stack || true
  echo "The private production health check failed." >&2
  exit 1
fi

caddy_backup="$bundle_dir/caddy.previous"
had_caddy_config=false
if [[ -f $caddy_target ]]; then
  cp -- "$caddy_target" "$caddy_backup"
  had_caddy_config=true
fi

restore_caddy() {
  if [[ $had_caddy_config == true ]]; then
    install -m 0644 "$caddy_backup" "$caddy_target"
  else
    rm -f -- "$caddy_target"
  fi
}

install -m 0644 \
  "$source_dir/deploy/production/delami-shipping-rates-api-production.caddy" \
  "$caddy_target"
if ! caddy validate --config /etc/caddy/Caddyfile; then
  restore_caddy
  rollback_stack || true
  echo "Caddy validation failed; the previous route and release were restored where available." >&2
  exit 1
fi

if ! systemctl reload caddy; then
  restore_caddy
  systemctl reload caddy || true
  rollback_stack || true
  echo "Caddy reload failed; the previous route and release were restored where available." >&2
  exit 1
fi

public_healthy=false
for _ in $(seq 1 18); do
  if curl --fail --silent --show-error --max-time 10 "$public_health_url" >/dev/null; then
    public_healthy=true
    break
  fi
  sleep 5
done
if [[ $public_healthy != true ]]; then
  restore_caddy
  systemctl reload caddy || true
  rollback_stack || true
  echo "The public HTTPS health check failed; the previous route and release were restored where available." >&2
  exit 1
fi

release_file=$(mktemp "$app_dir/.release.XXXXXX")
chmod 0600 "$release_file"
{
  printf 'COMMIT_SHA=%s\n' "$release_id"
  printf 'PREVIOUS_COMMIT_SHA=%s\n' "$previous_release_id"
  printf 'APP_IMAGE=%s\n' "$app_image"
  printf 'DEPLOYED_AT=%s\n' "$(date --utc +%Y-%m-%dT%H:%M:%SZ)"
} >"$release_file"
mv -f -- "$release_file" "$app_dir/release.env"

while IFS= read -r image; do
  [[ -n $image ]] || continue
  if [[ $image == "$app_image" ]]; then
    continue
  fi
  if [[ -n $previous_release_id && $image == "$image_repository:$previous_release_id" ]]; then
    continue
  fi
  docker image rm "$image" || true
done < <(
  docker image ls "$image_repository" \
    --filter label=com.delami.project=shipping-rates-api \
    --filter label=com.delami.environment=production \
    --format '{{.Repository}}:{{.Tag}}'
)
docker image prune \
  --force \
  --filter label=com.delami.project=shipping-rates-api \
  --filter label=com.delami.environment=production

echo "Shipping-rates API production release $release_id is healthy and active."
