# Contact Monitor Synchronizer

A dedicated Laravel service that bridges external data sources with the main **Contact Monitor** application. It pulls raw records from WHMCS, Gmail, IMAP mailboxes, Slack, Discord, and MetricsCube — stores them locally — then normalizes and pushes them to Contact Monitor via its ingest API.

---

## What this is and how it fits in

```
External systems          Synchronizer                   Contact Monitor
─────────────────         ──────────────────             ──────────────────
WHMCS addon API  ─┐       Import → source_* tables       Ingest API
Gmail API        ─┤  →→   Normalize → canonical items  →  Companies / People
IMAP mailboxes   ─┤  →→   POST /api/ingest/batch      →→  Conversations / Activity
Slack Web API    ─┤
Discord REST API ─┤       REST API ←── Contact Monitor
MetricsCube API  ─┘       (manages connections, triggers runs, reads logs)
```

**Contact Monitor** is the primary application that stores and displays data. It connects to the synchronizer during initial setup (Setup Assistant), registers its ingest secret, and thereafter calls the synchronizer's REST API to trigger syncs and read run status.

**The synchronizer** does not display data to users. It is a background service managed either via the Contact Monitor admin panel or its own lightweight admin UI at `/admin`.

---

## Table of Contents

- [Quick start](#quick-start)
- [Environment variables](#environment-variables)
- [Contact Monitor integration](#contact-monitor-integration)
- [Admin panel](#admin-panel)
- [Connections reference](#connections-reference)
  - [WHMCS](#whmcs)
  - [Gmail (OAuth)](#gmail-oauth)
  - [Cloudflare Tunnel (required for Gmail OAuth)](#cloudflare-tunnel-required-for-gmail-oauth)
  - [IMAP](#imap)
  - [Discord](#discord)
  - [Slack](#slack)
  - [MetricsCube](#metricscube)
- [REST API reference](#rest-api-reference)
- [Scheduling](#scheduling)
- [Troubleshooting](#troubleshooting)
- [Adding a new connector type](#adding-a-new-connector-type)

---

## Quick start

**1. Start the stack**
```bash
docker compose up -d
```

**2. Configure `.env`**
```bash
cp .env.example .env
```

Minimum required values:
```env
APP_KEY=                    # fill after step 3
APP_URL=http://localhost:8080

DB_DATABASE=contact-monitor-synchronizer
DB_USERNAME=contact-monitor-synchronizer
DB_PASSWORD=contact-monitor-synchronizer

ADMIN_PASSWORD=choose_a_strong_password
API_TOKEN=generate_a_random_token_here

CONTACT_MONITOR_INGEST_URL=http://host.docker.internal:8090
CONTACT_MONITOR_INGEST_SECRET=   # filled by Contact Monitor during setup wizard
```

**3. Generate app key and run migrations**
```bash
docker exec contact-monitor-synchronizer_app php artisan key:generate
docker exec contact-monitor-synchronizer_app php artisan migrate
```

**4. Open the admin panel**

Navigate to `http://localhost:8080/admin` — log in with `ADMIN_PASSWORD`.

**5. Register with Contact Monitor**

Go to Contact Monitor → Configuration → Synchronizer → Add Server. Enter `http://localhost:8080` and the `API_TOKEN`. Contact Monitor will call `POST /api/register` to exchange tokens, then automatically populate `CONTACT_MONITOR_INGEST_SECRET` in the synchronizer.

**6. Create connections**

In the admin panel: **Connections → New Connection** — choose a type, enter credentials, configure schedule, save.

---

## Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_KEY` | Yes | Laravel encryption key. Generate: `php artisan key:generate` |
| `APP_URL` | Yes | Must be HTTPS when using Gmail OAuth (Cloudflare Tunnel) |
| `ADMIN_PASSWORD` | Yes | Admin panel password. Default: `admin` — change before exposing publicly |
| `API_TOKEN` | Yes | Bearer token for `/api/*`. Contact Monitor uses this to authenticate |
| `CONTACT_MONITOR_INGEST_URL` | Yes | URL of Contact Monitor app, e.g. `http://host.docker.internal:8090` |
| `CONTACT_MONITOR_INGEST_SECRET` | Yes | Shared secret for `X-Ingest-Secret` header. Set by Contact Monitor during setup |
| `CM_REGISTRATION_TOKEN` | Setup only | Token used during Contact Monitor setup wizard handshake |
| `CM_REGISTRATION_URL` | Setup only | Contact Monitor `/api/register` endpoint |

All **connection credentials** (WHMCS tokens, IMAP passwords, bot tokens) are stored in the **database**, not in `.env`.

---

## Contact Monitor integration

The synchronizer and Contact Monitor communicate in both directions:

### Contact Monitor → Synchronizer (management)

Contact Monitor's admin panel calls the synchronizer REST API using `API_TOKEN`:

| What | How |
|------|-----|
| List connections | `GET /api/connections` |
| Trigger a sync run | `POST /api/connections/{id}/run` |
| Read run status | `GET /api/runs/{runId}` |
| Stream live logs | `GET /api/runs/{runId}/stream` (SSE) |
| Create/edit/delete connections | Full CRUD via `/api/connections` |

### Synchronizer → Contact Monitor (data)

After each import completes, the synchronizer pushes normalized data to Contact Monitor:

```
POST {CONTACT_MONITOR_INGEST_URL}/api/ingest/batch
X-Ingest-Secret: {CONTACT_MONITOR_INGEST_SECRET}
Content-Type: application/json

{
  "batch_id": "uuid",
  "source_type": "whmcs|imap|slack|discord|metricscube",
  "source_slug": "my-instance",
  "items": [ ... up to 250 canonical items ... ]
}
```

Batches are retried 3 times (2s / 4s backoff) on HTTP 5xx. A `401` or `422` aborts immediately.

### Docker networking

Inside Docker, the synchronizer app reaches Contact Monitor via `host.docker.internal`:

```env
CONTACT_MONITOR_INGEST_URL=http://host.docker.internal:8090
```

Contact Monitor reaches the synchronizer via container name:
```
http://contact-monitor-synchronizer_app:8000
```

The `docker-compose.yml` includes `extra_hosts: host.docker.internal:host-gateway` to enable this.

---

## Admin panel

**URL:** `http://localhost:8080/admin`

Single-password login (`ADMIN_PASSWORD` in `.env`).

### Connections list

Shows all connections with: type badge, system slug, schedule, last run status, duration.

Actions per row:
- **▶ Run** — dispatch immediately, open live log popup
- **Logs** — view last run logs (streams if still active)
- **Edit** — full settings form
- **⧉ Dupe** — clone connection (update name + slug before use)
- **■ Stop** — cancel active run
- **✕ Delete** — delete connection + all run history

### Data Stats

Row counts per `source_*` table, records changed in last 24h, per-system breakdown.

### Real-time logs

All job output is stored in `connection_runs.log_lines` (JSONB). Running jobs stream new lines via SSE. Log lines are color-coded: white = info, yellow = warning, red = error.

---

## Connections reference

### WHMCS

Requires the **Contact Monitor for WHMCS** addon installed on your WHMCS instance (see `/home/konrad/contact-monitor-for-whmcs`).

**Settings:**
| Field | Description |
|-------|-------------|
| Base URL | WHMCS root URL, e.g. `https://billing.example.com` |
| API Token | Bearer token configured in the WHMCS addon settings |
| Entities | Which data to import: clients, contacts, services, tickets (default: all) |

**What is imported:**
- Clients → `source_whmcs_clients` (with revenue data normalized to USD)
- Contacts → `source_whmcs_contacts`
- Services → `source_whmcs_services` (products + addons, status, revenue, renewal count)
- Tickets → `source_whmcs_tickets` (opening message + all replies, last 3 years)

**Cursor type:** `after_id` per entity. Tickets use dual cursor: `after_sent_at` + `after_ticket_id`.

**MetricsCube link:** If a MetricsCube connection references this WHMCS connection, it runs automatically as a sibling when WHMCS completes.

**Reset a checkpoint:**
```sql
-- On the synchronizer DB (port 5433)
DELETE FROM import_checkpoints
WHERE source_system = 'your-slug'
  AND importer = 'whmcs_api'
  AND entity = 'clients';   -- or contacts / services / tickets
```

---

### Gmail (OAuth)

**Settings:**
| Field | Description |
|-------|-------------|
| Client ID | Google OAuth client ID |
| Client Secret | Google OAuth client secret |
| Subject email | The Gmail account to import |
| Search query | Optional Gmail search string, e.g. `in:inbox` |
| Excluded labels | Labels to skip (one per line) |
| Page size | Messages per API page (default 100, max 500) |
| Max pages | 0 = unlimited |

**Setup steps:**

1. In [Google Cloud Console](https://console.cloud.google.com/): Create an OAuth 2.0 Web Client. Enable Gmail API. Add the redirect URI shown in the connection form: `https://your-domain.example.com/google/callback/{slug}`.
2. Create the Gmail connection in the admin panel. Copy OAuth Client ID and Secret into the form. Save.
3. Open **Edit** → click **▶ Authorize with Google**. Complete the consent flow. Status should switch to "Token active".

> Google requires the callback URL to be HTTPS. Use [Cloudflare Tunnel](#cloudflare-tunnel-required-for-gmail-oauth).

> If you get "No refresh_token received": revoke the app at [myaccount.google.com/permissions](https://myaccount.google.com/permissions) and authorize again.

---

### Cloudflare Tunnel (required for Gmail OAuth)

Google OAuth requires a public HTTPS callback URL. Cloudflare Tunnel exposes the synchronizer without opening firewall ports.

```bash
# 1. Install cloudflared and log in
cloudflared tunnel login

# 2. Create tunnel
cloudflared tunnel create contact-monitor-synchronizer-oauth
# Note the Tunnel ID (UUID)

# 3. Create ~/.cloudflared/config.yml:
#   tunnel: <TUNNEL_ID>
#   credentials-file: /home/<you>/.cloudflared/<TUNNEL_ID>.json
#   ingress:
#     - hostname: oauth.your-subdomain.example.com
#       service: http://localhost:8080
#     - service: http_status:404

# 4. Route DNS
cloudflared tunnel route dns contact-monitor-synchronizer-oauth oauth.your-subdomain.example.com

# 5. Run (or install as systemd service)
cloudflared tunnel run contact-monitor-synchronizer-oauth
```

Add to `.env` when using the tunnel:
```env
APP_URL=https://oauth.your-subdomain.example.com
SESSION_DOMAIN=oauth.your-subdomain.example.com
```

---

### IMAP

**Settings:**
| Field | Description |
|-------|-------------|
| Host | IMAP server hostname |
| Port | 993 (IMAPS), 143 (STARTTLS / no enc) |
| Encryption | `ssl` / `tls` / `none` |
| Username | Full email address |
| Password | Email account password |
| Excluded mailboxes | Mailboxes to skip (one per line). All others are scanned. |
| Batch size | Messages per batch (default 100) |
| Max batches | 0 = unlimited |

**What is imported:** All mailboxes (except excluded). Headers + text/plain + text/html body. Attachments are not stored.

**Cursor:** `last_uid` per mailbox, stored in `import_checkpoints`.

---

### Discord

**Setup:**
1. [Discord Developer Portal](https://discord.com/developers/applications) → New Application → Bot → Add Bot → copy Token
2. Enable **Message Content Intent** under Privileged Gateway Intents
3. Invite bot: OAuth2 → URL Generator → scopes: `bot`, permissions: `Read Messages`, `Read Message History`
4. Enable Developer Mode in Discord (User Settings → Advanced). Right-click server → Copy Server ID. Right-click channel → Copy Channel ID.

**Settings:**
| Field | Description |
|-------|-------------|
| Bot Token | Discord bot token |
| Guild allowlist | Server IDs to import (one per line). Empty = all guilds the bot can see. |
| Channel allowlist | Channel IDs to import (one per line). Empty = all accessible channels. |
| Import thread replies | Whether to fetch replies in threads |
| Max messages per run | 0 = unlimited (use for first full run; limit for scheduled partials) |

**Modes:**
- **Full** — paginate backward from newest message to oldest for each channel
- **Partial** — fetch only messages newer than last checkpoint

**Cursor:** Per channel — `discord|messages|{slug}|{channel_id}` in `import_checkpoints`.

---

### Slack

**Setup:**
1. [api.slack.com/apps](https://api.slack.com/apps) → Create New App → From scratch
2. OAuth & Permissions → Bot Token Scopes: `channels:read`, `channels:history`, `groups:read`, `groups:history`, `files:read`
3. Install to Workspace → copy Bot User OAuth Token (`xoxb-...`)
4. For private channels: `/invite @your-bot-name` in each channel

**Settings:**
| Field | Description |
|-------|-------------|
| Bot Token | `xoxb-...` |
| Channel allowlist | Channel IDs to import (one per line). Empty = all channels the bot is in. |
| Import thread replies | Whether to fetch thread replies |
| Max messages per run | 0 = unlimited |

**Finding channel IDs:** Open channel in Slack → click channel name → scroll to bottom of details panel. Or: right-click channel → Copy link → last segment of URL.

**Cursor:** Per channel — `slack|messages|{slug}|{channel_id}` in `import_checkpoints`.

---

### MetricsCube

MetricsCube does **not** run independently. It is linked to a WHMCS connection and runs automatically when that WHMCS connection completes.

**Settings:**
| Field | Description |
|-------|-------------|
| Linked WHMCS connection | Which WHMCS connection triggers this |
| App Key | MetricsCube app key |
| Connector Key | MetricsCube connector key |

MetricsCube connections have no schedule or manual run button — they appear in run history mirroring the WHMCS run.

---

## REST API reference

All endpoints (except `/api/register`) require:
```
Authorization: Bearer {API_TOKEN}
```

### Settings
```
GET  /api/settings          → { ingest_url, has_ingest_secret }
PUT  /api/settings          Body: { ingest_url?, ingest_secret? }
POST /api/run-all           → Dispatch all active connections (partial mode)
POST /api/reset-runs        → Mark stuck running runs as failed
```

### Connections
```
GET    /api/connections
POST   /api/connections                    Body: { type, name, system_slug, settings, schedule... }
GET    /api/connections/{id}
PUT    /api/connections/{id}
DELETE /api/connections/{id}
POST   /api/connections/{id}/duplicate
POST   /api/connections/test              Body: { type, settings } → { ok, message }
POST   /api/connections/{id}/run          Body: { mode: "partial"|"full" }
POST   /api/connections/{id}/stop
POST   /api/kill-all
GET    /api/connections/{id}/runs
```

### Runs
```
GET  /api/runs                            ?status=&since=&page=
GET  /api/runs/{runId}                    → { id, status, started_at, finished_at, duration_seconds, error_message }
GET  /api/runs/{runId}/logs               → { log_lines: [...] }
GET  /api/runs/{runId}/stream             SSE — streams log lines, closes when run ends
```

### Registration
```
POST /api/register          Body: { verify_token }   No auth required
```

---

## Scheduling

The `scheduler` container runs `connections:run-scheduled` every 60 seconds. That command:
1. Finds all `is_active=true` connections with `schedule_enabled=true` or `schedule_full_enabled=true`
2. Checks the cron expression against the current time
3. Skips if a run is already pending or running for that connection
4. Dispatches `RunConnection` jobs for due connections

Full schedule takes priority over partial when both are due simultaneously. MetricsCube connections are always skipped (they run as WHMCS siblings).

**Cron presets** available in the admin form: every 15 min, every 30 min, hourly, every 6h, daily, weekly.

---

## Troubleshooting

### Nothing is syncing to Contact Monitor

1. Check `CONTACT_MONITOR_INGEST_URL` — must be reachable from inside the Docker container:
   ```bash
   docker exec contact-monitor-synchronizer_app curl http://host.docker.internal:8090/api/ingest/batch
   # Expect 401, not connection refused
   ```
2. Check `CONTACT_MONITOR_INGEST_SECRET` is set and matches what Contact Monitor expects.
3. Check worker is running: `docker compose ps` — `worker` should be `Up`.
4. Check worker logs: `docker compose logs -f worker`

### Runs stuck in "running" state

Worker crashed mid-job. Reset stuck runs:
```bash
docker exec contact-monitor-synchronizer_app php artisan tinker
# >>> App\Models\ConnectionRun::where('status','running')->update(['status'=>'failed'])
# >>> exit
```
Or via the API: `POST /api/reset-runs`

### Worker not picking up jobs

```bash
# Check queue table
docker exec contact-monitor-synchronizer_app php artisan queue:monitor database

# Restart worker
docker compose restart worker
```

### Run fails with "Contact Monitor auth failed (401)"

`CONTACT_MONITOR_INGEST_SECRET` is wrong or missing. In Contact Monitor, go to Configuration → Synchronizer → click the server → re-register, or manually copy the secret and update `.env`:
```bash
docker compose exec app php artisan config:clear
docker compose restart worker
```

### Import checkpoint wrong / want to re-import from scratch

Delete the checkpoint for the specific source:
```sql
-- Connect to synchronizer DB: psql -h localhost -p 5433 -U contact-monitor-synchronizer
DELETE FROM import_checkpoints WHERE source_system = 'your-slug';
-- Or more granular:
DELETE FROM import_checkpoints WHERE source_system = 'your-slug' AND importer = 'whmcs_api' AND entity = 'tickets';
```

Then trigger a Full run.

### Export checkpoint wrong / want to re-export to Contact Monitor

```sql
DELETE FROM import_checkpoints WHERE importer = 'salesos_export' AND source_system = 'your-slug';
```

Contact Monitor's ingest API is idempotent (uses `idempotency_key`), so re-exporting the same data is safe.

### Gmail "No refresh_token received"

The account was previously authorized. Revoke access:
1. Go to [myaccount.google.com/permissions](https://myaccount.google.com/permissions)
2. Find your OAuth app → Remove access
3. Click **▶ Authorize with Google** again in the connection edit page

### Gmail OAuth popup fails / redirect URL mismatch

- Verify `APP_URL` in `.env` exactly matches the domain registered in Google Cloud Console
- Verify the Cloudflare Tunnel is running and the tunnel domain resolves
- Check Google Cloud Console → Credentials → Authorised redirect URIs includes the exact URL shown in the connection form

### Scheduler not firing

```bash
docker compose ps   # check scheduler is Up
docker compose logs -f scheduler
# Should see: "Running scheduled command..." every ~60s
```

If it's stopped: `docker compose restart scheduler`

### "Unknown connection type" error in run log

A connection's `type` field has a value not handled by `RunConnection::runImporter()`. This happens if you manually edited the database. Fix by updating the connection type to a valid value: `whmcs`, `gmail`, `imap`, `discord`, `slack`, `metricscube`.

### Container cannot reach host.docker.internal

Some Linux Docker setups don't support `host.docker.internal` by default. The `docker-compose.yml` adds `extra_hosts: host.docker.internal:host-gateway` which fixes this. If it still fails:
```bash
# Find host IP from inside container
docker exec contact-monitor-synchronizer_app ip route | grep default | awk '{print $3}'
# Use that IP directly in CONTACT_MONITOR_INGEST_URL
```

### View raw logs from a run

```bash
# Via artisan tinker
docker exec contact-monitor-synchronizer_app php artisan tinker
# >>> App\Models\ConnectionRun::latest()->first()->log_lines
```

Or query directly:
```sql
SELECT id, status, log_lines FROM connection_runs ORDER BY id DESC LIMIT 5;
```

---

## Adding a new connector type

Four places to change:

1. **`app/Importers/NewType/ImportNewType.php`** — implement `run(callable $log): void`
2. **`app/Jobs/RunConnection.php`** — add case in `match($connection->type)` + `private function runNewType()`
3. **`app/Exporters/Normalizers/NewTypeNormalizer.php`** — implement `normalize(?string $sinceAt, callable $log): \Generator`
4. **`app/Http/Controllers/Admin/ConnectionController.php`** — add credential validation in `test()`
5. **`resources/views/admin/connections/form.blade.php`** — settings section `x-show="type === 'newtype'"`

Optionally add migration for a `source_newtype_*` table and a CSS badge class.
