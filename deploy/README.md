# Production deployment

GitHub mirrors every pushed branch to GitLab. GitLab creates a pipeline only
for a push to the `production` branch. There is intentionally no staging
environment or staging deployment job for this project.

The pipeline validates the CodeIgniter 4 application, uploads the exact checked-out
commit and a production environment file to the deployment VM over SSH, builds
the image on that VM, and replaces the production Compose service. It does not
need GitLab Container Registry, Docker-in-Docker, or a privileged runner.

## Application and container layout

The image uses PHP 8.4 with Apache and Composer's locked production
dependencies. Apache serves only `public/` and listens on port `8080` inside
the container. The host exposes that container only as `127.0.0.1:15004`, and
Caddy publishes it as `https://rates.delamibrands.com` and redirects plain HTTP
requests to HTTPS.

The CI job installs the PHP extensions required by the application, validates
`composer.json`, installs the locked dependencies, syntax-checks `app/` and
`tests/`, and runs PHPUnit. The `/health` route queries the configured database
and returns 2xx only when the application is ready.

`writable/` is a persistent Compose volume shared by the migration and web
containers. It retains the SQLite database, sessions, uploads, logs, and cache
across image replacements. Everything else in the container is read-only.

## GitLab runner

Use a project or group Docker runner with these settings:

- Tag: `delami-deploy`
- Run untagged jobs: off
- Protected: on
- Lock to current project: on
- Maximum job timeout: `3600`

If the PayloadCMS runner is locked to its project, register a separate runner
for this GitLab project with the same tag. The Docker executor can remain
non-privileged because deployment reaches the host's Docker daemon over SSH.

On the deployment VM, register the runner using the authentication token shown
after creating it in GitLab:

```bash
gitlab-runner register --non-interactive \
  --url https://gitlab.delamibrands.com \
  --token '<new runner authentication token>' \
  --executor docker \
  --docker-image alpine:3.22 \
  --description delami-shipping-rates-api-deploy
```

## Required GitLab variables

Add these project CI/CD variables as **File** variables:

- `SSH_PRIVATE_KEY`: the unencrypted key authorized for the production SSH
  account.
- `PRODUCTION_ENV_FILE`: the completed application environment based on
  `deploy/production/stack.env.example`, scoped to `production`.

The production environment is fail-closed: it must select SQLite, use the
public HTTPS base URL, disable database debug output, retain secure cookies,
use an HTTPS courier proxy, and supply non-placeholder encryption and carrier
callback secrets. Replace or remove the example storefront origin before
uploading the file. The deploy script treats HTTPS forcing, secure cookies,
and disabled SQLite debug output as production invariants and normalizes those
three values before validating and installing the file.

Mark both variables protected and masked where GitLab permits it, and protect
the `production` branch. The GitHub `GITLAB_PROJECT_ACCESS_TOKEN` must also be
allowed to push the mirrored commit to that protected branch.

The default SSH endpoint is `110.239.91.57:9022`. The tracked known-host file
pins its ED25519 key. Verify rotations from a trusted server console and then
run:

```bash
./deploy/production/regenerate-known-hosts.sh 'SHA256:trusted-fingerprint'
```

Review and commit the regenerated file.

## Deployment and rollback

Each image is tagged with its source commit SHA. Deployments are serialized by
GitLab's `resource_group`, and production is considered successful only after
the Compose health check, direct loopback health check, Caddy validation, and
public HTTPS health check all pass.

Before replacing the web container, deployment runs `php spark migrate --all`
in a one-shot container. For SQLite it also verifies that WAL mode is enabled.
The current and previous images are retained. If replacement or health checks
fail, the script restores the previous image and Caddy route when possible.
Database migrations are not automatically reversed.

The deployment host must provide Docker with Compose v2, Bash, curl, Caddy,
and systemd. DNS for `rates.delamibrands.com` must point at the VM
before the first deployment can pass its public health check.

To inspect the active release on the host:

```bash
cat /opt/delami/shipping-rates-api/production/release.env
```

## First production release

The first deployment creates the schema but deliberately does not create an
administrator. After it succeeds, seed the first administrator once from the
host. Substitute real credentials and keep the password out of shell history
where possible:

```bash
cd /opt/delami/shipping-rates-api/production
APP_IMAGE="$(sed -n 's/^APP_IMAGE=//p' release.env)" \
  docker compose \
    --project-name delami-shipping-rates-api-production \
    --env-file stack.env \
    --file compose.yml \
    run --rm \
    -e ADMIN_EMAIL='operator@delamibrands.com' \
    -e ADMIN_PASSWORD='use-a-secret-value' \
    app php spark db:seed App\\Database\\Seeds\\AdminUserSeeder
```

Back up the `delami-shipping-rates-api-production_writable` volume before
deployments that contain destructive migrations. The production deployment
intentionally accepts only SQLite, so that volume contains the live database.
