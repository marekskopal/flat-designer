# Self-hosting Izbik

This guide sets up Izbik on a server of your own, keeps it backed up and upgrades it. The README's
[Getting started](../README.md#getting-started) covers a local install for trying it out or developing.

Izbik is in beta. Back up before every upgrade, and read the [changelog](../CHANGELOG.md) first.

## What runs

`docker compose` runs the whole stack on one machine:

| Service | What it is |
| --- | --- |
| `proxy` | nginx on `PROXY_PORT` (and `PROXY_PORT_SSL` with HTTPS): the only service reachable from outside |
| `frontend` | the Angular app, served by nginx |
| `backend` | the API, the MCP server and the live updates (FrankenPHP); applies the database migrations on start |
| `db` | MariaDB, its data in the `izbik_data` volume |
| `minio` | S3-compatible storage for uploaded files and models, in the `izbik_files` volume (unused with external S3) |

A small server is enough: 2 CPU cores, 2 GB of memory and disk for the uploads. The 3D view runs in the browser.

## Install

Requirements: Docker with Compose, `make`, `openssl` and `git`.

```sh
git clone --branch v0.2.0 https://github.com/marekskopal/izbik.git
cd izbik
make env        # creates .env with random secrets and passwords
```

Go through `.env` (see [Production settings](#production-settings)), then start one of two ways:

- `make deploy` runs the published images of `IZBIK_VERSION` from GitHub's container registry
  (`ghcr.io/marekskopal/izbik-{backend,frontend,proxy}`). Nothing is built on the server. The images serve the app at
  the root of its domain.
- `make up` builds the images from the checkout. Use it to serve the app under a path (`APP_PATH`) or to run changes
  of your own.

Then create the first admin and sign in:

```sh
make admin
```

Sign-up is closed by default: admins add the accounts on the **Users** page.

## Production settings

`make env` already gives a new `.env` random values for `AUTHORIZATION_TOKEN_KEY`, the Mercure keys,
`MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` and `S3_SECRET_KEY`. Do not reuse the examples' passwords on a server. The
database keeps the passwords it was created with, so change them before the first start or not at all.

Check these before going live:

| Setting | Production value |
| --- | --- |
| `PROXY_HOST` | The public hostname, e.g. `izbik.example.com`. MCP clients and OAuth discovery need it |
| `BACKEND_CORS_ALLOWED_ORIGIN` | Empty (the default). The app and the API share one origin, so no cross-origin calls are needed. An older `.env` may still have `*` |
| `SIGN_UP_ENABLED` | `0` (the default) unless anyone on the internet may create an account |
| `SMTP_*`, `EMAIL_FROM` | A mail server, for password reset and email verification. Without one, reset passwords with `make reset-password` |
| `GOOGLE_CLIENT_ID` | Optional, Sign in with Google: authorize `https://<PROXY_HOST>` as a JavaScript origin |
| `BACKEND_LOG_LEVEL` | `production` |
| `FILE_MAX_SIZE_MB` | The largest upload you accept |
| `IMPORT_MAX_SIZE_MB` | The largest project file (unpacked) a user may import, default 500; nginx and PHP take 512 MB at most |

Keep `.env` private: it holds every secret of the installation. Back it up apart from the data, because a backup of
the database is useless without the token key and passwords.

### HTTPS

Serve Izbik over HTTPS on a domain. Choose one of two setups:

- **TLS in the proxy.** Uncomment `COMPOSE_FILE=docker-compose.yml:docker-compose.ssl.yml` in `.env`, set
  `PROXY_PORT=80`, `PROXY_PORT_SSL=443` and point `PROXY_SSL_CERT` / `PROXY_SSL_KEY` at the certificate (full chain) and
  its key, e.g. from Let's Encrypt's certbot. HTTP then redirects to HTTPS, and HSTS is on. After renewing the
  certificate, reload the proxy: `docker compose restart proxy`.
- **TLS in front of the stack.** A reverse proxy you already run (Caddy, Traefik, a load balancer) terminates TLS and
  forwards to `PROXY_PORT` on the machine. It must pass `Host` and `X-Forwarded-Proto: https`, and keep server-sent
  events unbuffered for `/.well-known/mercure` and `/mcp`. Bind `PROXY_PORT` to the loopback or a private network.

Set `PROXY_HOST` in both cases.

### External S3

The bundled MinIO keeps the files in a Docker volume on the same machine. To use S3 or another S3-compatible service:

```ini
S3_ENDPOINT=                 # empty for AWS; the service's URL for others
S3_REGION=eu-central-1
S3_BUCKET=izbik-files        # created on first use if the key may do so
S3_ACCESS_KEY=…
S3_SECRET_KEY=…
S3_USE_PATH_STYLE=false      # true for MinIO and services that need path-style URLs
```

The bucket stays private: the backend reads and writes the files, and the browser never fetches them from S3. MinIO
still starts, unused. To move existing files, back up, switch the settings, restore (see below).

### Security headers

The proxy sends a strict Content-Security-Policy with every page (`proxy/conf/app-headers.conf`). Scripts load only
from the server, with a nonce per request, plus Google's sign-in script. Styles may come from Google Fonts. The
policy also forbids framing the app. If you embed the app elsewhere or add scripts from another origin, change that
file and rebuild the proxy (`make up`).

## Backups

```sh
make backup                               # into backups/<date-time>/
make backup BACKUP=backups/before-0.2.0   # or a directory of your choice
```

A backup holds:

- `database.sql.gz`: a consistent dump of the database, made while the app keeps running;
- `files/`: every stored file and model, with `files/objects.json` listing their content types. This works for MinIO
  and external S3 alike.

`backups/` is in the checkout and ignored by git. Copy it off the machine, e.g. with a nightly cron job:

```cron
30 3 * * * cd /srv/izbik && make backup BACKUP=backups/nightly && rsync -a --delete backups/nightly/ backup-host:izbik/
```

`make backup` dumps the bundled MariaDB only. With a database of your own (`MYSQL_HOST` other than `db`), dump it with
your usual tools and keep `make backup` for the files.

### Restoring

```sh
make restore BACKUP=backups/<date-time>
```

The restore asks first. It stops the backend, replaces the whole database with the backup's and puts the backup's files
back into the storage, then starts the backend again, which migrates the restored database if the backup is older than
the installed version. Files uploaded after the backup stay in the storage, unused.

A restore to a new machine: install as above with the old `.env` (at least its `AUTHORIZATION_TOKEN_KEY`; without it,
everyone has to sign in again), start the stack, then `make restore`. Try a restore once before you
rely on your backups.

## Upgrading

1. Read the [changelog](../CHANGELOG.md) from your version up to the new one, for notes on settings.
2. Back up: `make backup BACKUP=backups/before-<version>`.
3. Get the new version:
   - with the published images: set `IZBIK_VERSION=<version>` in `.env` (e.g. `0.2.0`), then also
     `git fetch --tags && git checkout v<version>` for the matching compose file and Makefile;
   - built from source: `git fetch --tags && git checkout v<version>`.
4. Compare `.env.example` with your `.env` and add new settings you want (missing ones take their defaults).
5. Start it: `make deploy` (images) or `make up` (source). The backend applies the new migrations on start. Wait until
   `docker compose ps` shows the backend healthy.

Upgrades go forward from any earlier release, as the migrations of every release in between run in order. There is
no downgrade: to go back, check out the old version and restore the backup made before the upgrade.

`IZBIK_VERSION=latest` follows the newest release, pre-releases included. Pin a version on a server you care about, so an
upgrade happens only when you choose.

## Releases

Versions follow [semantic versioning](https://semver.org). A tag `v<version>` (e.g. `v0.1.0`) on the
open-source repository publishes the three images as `<version>` and `latest`, and a GitHub release with the
version's notes from [CHANGELOG.md](../CHANGELOG.md) (a pre-release for versions with a suffix such as `-rc.1`).

To publish a release (maintainers):

1. Move the changelog's *Unreleased* entries under a new `## [<version>] - <date>` heading and commit.
2. `git tag v<version> && git push public v<version>`. The *Release* workflow builds and pushes the images
   (amd64 and arm64), then creates the GitHub release.
3. After the first release only: in each package's settings on GitHub (`izbik-backend`, `izbik-frontend`,
   `izbik-proxy`), change the visibility to public. Public packages are free and can be pulled without signing in.
