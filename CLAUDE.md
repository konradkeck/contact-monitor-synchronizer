# contact-monitor-synchronizer — CLAUDE.md

## What this is

The **synchronizer** is a separate Laravel service that sits between external data sources (WHMCS, Slack, Discord, IMAP, Gmail, MetricsCube) and the main **contact-monitor** application.

Its job:
1. **Import** raw records from external APIs into local `source_*` tables (PostgreSQL)
2. **Export** normalized canonical items to contact-monitor via `POST /api/ingest/batch`
3. **Expose a REST API** so contact-monitor can manage connections, trigger runs, and read logs
4. **Provide an admin UI** at `/admin` for manual management

contact-monitor does **not** pull data directly. All data flows through the synchronizer.

---

## Stack

- Laravel 12, PHP 8.3
- PostgreSQL 16 (port 5433 on host, 5432 inside Docker)
- Queue driver: database (no Redis)
- Session/Cache: database
- SSE for real-time log streaming (no WebSocket)

---

## Docker Services

| Container | Role | Port |
|-----------|------|------|
| `contact-monitor-synchronizer_app` | PHP dev server (admin UI + REST API) | 8080 |
| `contact-monitor-synchronizer_worker` | Queue consumer (`queue:work`) | — |
| `contact-monitor-synchronizer_scheduler` | Cron runner (`schedule:run` every 60s) | — |
| `contact-monitor-synchronizer_db` | PostgreSQL 16 | 5433 |

All containers share the same codebase via volume mount. Worker and scheduler restart automatically.

---

## Environment Variables

```env
APP_KEY=                              # Required. Generate with: php artisan key:generate
APP_URL=http://localhost:8080         # Must be HTTPS when using Gmail OAuth

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=contact-monitor-synchronizer
DB_USERNAME=contact-monitor-synchronizer
DB_PASSWORD=contact-monitor-synchronizer

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

ADMIN_PASSWORD=                       # Admin panel password. Default: admin (change it)
API_TOKEN=                            # Bearer token for /api/* endpoints (used by contact-monitor)

CONTACT_MONITOR_INGEST_URL=           # e.g. http://host.docker.internal:8090
CONTACT_MONITOR_INGEST_SECRET=        # Shared secret for X-Ingest-Secret header

CM_REGISTRATION_TOKEN=                # Token for initial registration handshake with contact-monitor
CM_REGISTRATION_URL=                  # contact-monitor /api/register endpoint (set during wizard)
```

All **connection credentials** (WHMCS tokens, IMAP passwords, bot tokens, etc.) are stored in the `connections.settings` JSON column in the database — NOT in `.env`.

Google OAuth refresh tokens are stored in `oauth_google_tokens`, encrypted with `Crypt::encryptString()` using `APP_KEY`.

---

## Common Commands

```bash
# Start everything
docker compose up -d

# Run migrations
docker exec contact-monitor-synchronizer_app php artisan migrate

# Generate app key
docker exec contact-monitor-synchronizer_app php artisan key:generate

# Restart worker (after code changes that affect jobs)
docker compose restart worker

# Restart scheduler
docker compose restart scheduler

# Tail logs
docker compose logs -f worker
docker compose logs -f app

# Queue status
docker exec contact-monitor-synchronizer_app php artisan queue:monitor database

# Manually trigger a sync export (without running an import)
docker exec contact-monitor-synchronizer_app php artisan contact-monitor:sync {connection_id}

# Reset all stuck runs to failed
docker exec contact-monitor-synchronizer_app php artisan tinker
# ConnectionRun::where('status','running')->update(['status'=>'failed'])
```

---

## Architecture

### Data flow

```
External source (WHMCS / Slack / Discord / IMAP / Gmail / MetricsCube)
    ↓ HTTP polling
Importer (app/Importers/{Type}/)
    ↓ upsert via SHA-256 row_hash change detection
source_* tables (PostgreSQL)
    ↓ after import completes
RunConnection job → dispatch SyncToSalesOs job
    ↓
Normalizer (app/Exporters/Normalizers/{Type}/)
    ↓ Generator yields canonical items in batches of 250
ContactMonitorClient::sendBatch()
    ↓ POST /api/ingest/batch with X-Ingest-Secret header
contact-monitor main app
```

### Job flow

```
Trigger (manual / scheduler / API)
    ↓
ConnectionRun row created (status: pending)
    ↓
RunConnection::dispatch($connectionId, $runId, $mode)
    ↓ queue:work picks it up
handle() {
    1. markRunning()
    2. If WHMCS: create sibling ConnectionRun for linked MetricsCube
    3. runImporter() → logs buffered (flush every 10 lines or 3s)
    4. markCompleted()
    5. SyncToSalesOs::dispatch($connectionId)
    6. If sibling: SyncToSalesOs::dispatch($siblingConnectionId)
}
    ↓
SyncToSalesOs::handle() {
    ContactMonitorExporter::exportConnection($connection)
    → Normalizer::normalize($sinceAt) → Generator
    → batch 250 items → ContactMonitorClient::sendBatch()
    → update checkpoint in import_checkpoints (importer='salesos_export')
}
```

### Modes

| Mode | Importer behaviour | Export behaviour |
|------|-------------------|-----------------|
| `partial` | Fetch only records newer than last cursor | Export only records updated since last export cursor |
| `full` | Paginate from beginning (cursor = 0) | Same as partial (export cursor is independent) |

Full mode is used for first run or to re-import everything. Partial is used for scheduled ongoing runs.

---

## File Structure

```
app/
├── Console/Commands/
│   ├── RunScheduledConnections.php     Cron: dispatches due RunConnection jobs every 60s
│   ├── Register.php                    Handshake registration with contact-monitor
│   ├── ContactMonitorSync.php          Manual export trigger
│   ├── WhmcsImport*.php                Thin CLI wrappers for WHMCS importers
│   ├── ImportGmail*.php                Thin CLI wrapper for Gmail importer
│   ├── ImportImap*.php                 Thin CLI wrapper for IMAP importer
│   └── MetricsCubeImport*.php          Thin CLI wrapper for MetricsCube importer
│
├── Exporters/
│   ├── ContactMonitorClient.php        HTTP client → contact-monitor /api/ingest/batch
│   ├── ContactMonitorExporter.php      Orchestrates per-type normalization + batch sending
│   └── Normalizers/
│       ├── WhmcsNormalizer.php         WHMCS source → account/identity/conversation/message
│       ├── ImapNormalizer.php          IMAP source → conversation/message/activity
│       ├── SlackNormalizer.php         Slack source → identity/conversation/message/activity
│       ├── DiscordNormalizer.php       Discord source → identity/conversation/message/activity
│       └── MetricsCubeNormalizer.php   MetricsCube source → activity
│
├── Http/Controllers/
│   ├── Admin/ConnectionController.php  Web session CRUD, test connection, OAuth flow
│   └── Api/
│       ├── ConnectionsController.php   REST API: CRUD, run, stop, kill, runs list, SSE stream
│       └── SettingsController.php      REST API: ingest URL, run-all, reset-runs
│
├── Importers/
│   ├── Discord/
│   │   ├── ImportDiscordMessages.php   REST API polling, full/partial mode, thread support
│   │   └── ImportDiscordMembers.php    Guild member list (captures users who never messaged)
│   ├── Gmail/
│   │   └── ImportGmailMessages.php     Gmail API, OAuth, concurrent Http::pool() fetch
│   ├── Imap/
│   │   └── ImportImapMessages.php      PHP imap ext, all mailboxes, UID-based cursor
│   ├── MetricsCube/
│   │   └── ImportMetricsCubeClientActivity.php  Linked to WHMCS, runs as sibling
│   ├── Slack/
│   │   └── ImportSlackMessages.php     Slack Web API, full/partial, thread replies
│   └── Whmcs/
│       ├── ImportWhmcsClients.php      after_id cursor on clientid
│       ├── ImportWhmcsContacts.php     after_id cursor on contactid
│       ├── ImportWhmcsServices.php     after_id cursor on serviceid
│       └── ImportWhmcsTickets.php      dual cursor: after_sent_at + after_ticket_id
│
├── Jobs/
│   ├── RunConnection.php               Core job: runs importer → dispatches SyncToSalesOs
│   └── SyncToSalesOs.php               Export job: normalizes source data → sends to CM
│
├── Models/
│   ├── Connection.php                  Connection definition, cron helpers
│   ├── ConnectionRun.php               Run history, status mutations, log append
│   └── OAuthGoogleToken.php            Encrypted Google refresh tokens
│
└── Support/
    ├── ImportCheckpointStore.php       K-V wrapper for import_checkpoints table
    ├── WhmcsConfig.php                 Credential loader for WHMCS connections
    ├── ImapConfig.php                  Credential loader for IMAP connections
    ├── GoogleOAuthConfig.php           Credential loader for Gmail OAuth
    ├── MetricsCubeConfig.php           Credential loader for MetricsCube connections
    └── GmailTokenProvider.php          OAuth token refresh (exchange refresh → access token)
```

---

## Database Tables

### Connection management

| Table | Purpose |
|-------|---------|
| `connections` | Connection definitions: type, name, system_slug, settings JSON, schedule crons, is_active |
| `connection_runs` | Run history: connection_id, status, triggered_by, log_lines (JSONB), error_message, duration |
| `import_checkpoints` | Cursor state: source_system, importer, entity, cursor_type, cursor_meta (JSON), last_run_at |
| `oauth_google_tokens` | system, subject_email, refresh_token (encrypted with APP_KEY) |

### Source data (raw imports, no normalization)

| Table | Source | Key columns |
|-------|--------|-------------|
| `source_whmcs_clients` | WHMCS addon API | clientid, payload_json, row_hash |
| `source_whmcs_contacts` | WHMCS addon API | contactid, payload_json, row_hash |
| `source_whmcs_services` | WHMCS addon API | serviceid, payload_json, row_hash |
| `source_whmcs_tickets` | WHMCS addon API | ticket_id, msg_id, payload_json, row_hash |
| `source_gmail_messages` | Gmail API | message_id, thread_id, payload_json, row_hash |
| `source_imap_messages` | PHP imap ext | account, mailbox, uid, headers, text_body, html_body, row_hash |
| `source_discord_channels` | Discord REST API | channel_id, guild_id, payload_json, bot_accessible |
| `source_discord_messages` | Discord REST API | message_id, channel_id, payload_json, row_hash |
| `source_discord_attachments` | Discord REST API | attachment_id, payload_json (metadata only, no binaries) |
| `source_discord_members` | Discord REST API | guild_id, user_id, payload_json |
| `source_slack_channels` | Slack Web API | channel_id, payload_json, is_private, row_hash |
| `source_slack_messages` | Slack Web API | ts, channel_id, payload_json, row_hash |
| `source_slack_files` | Slack Web API | file_id, payload_json (metadata only, no binaries) |
| `source_slack_users` | Slack Web API | user_id, payload_json |
| `source_metricscube_client_activities` | MetricsCube API | activity_id, payload_json, row_hash |

---

## REST API (used by contact-monitor)

All endpoints require: `Authorization: Bearer {API_TOKEN}`

**Base:** `http://contact-monitor-synchronizer_app:8000/api`

### Settings
```
GET  /api/settings          → { ingest_url, has_ingest_secret }
PUT  /api/settings          → Update ingest_url / ingest_secret
POST /api/run-all           → Dispatch all active connections
POST /api/reset-runs        → Mark running runs as failed, clear stuck queue
```

### Connections
```
GET    /api/connections                      → List all
POST   /api/connections                      → Create
GET    /api/connections/{id}                 → Show
PUT    /api/connections/{id}                 → Update
DELETE /api/connections/{id}                 → Delete
POST   /api/connections/{id}/duplicate       → Clone
POST   /api/connections/test                 → Test credentials (no save)
POST   /api/connections/{id}/run             → Trigger run { mode: "partial"|"full" }
POST   /api/connections/{id}/stop            → Stop active run
GET    /api/connections/{id}/runs            → Runs for this connection
POST   /api/kill-all                         → Stop ALL running jobs
```

### Runs
```
GET  /api/runs                       → List runs (pagination, filters: status, since)
GET  /api/runs/{runId}               → Status snapshot
GET  /api/runs/{runId}/logs          → Full log_lines JSON
GET  /api/runs/{runId}/stream        → SSE stream (real-time lines, closes on completion)
```

### Registration (no auth)
```
POST /api/register   → { verify_token }   Called by contact-monitor setup wizard
```

---

## Canonical Item Types (exported to contact-monitor)

| Type | Description | Sources |
|------|-------------|---------|
| `account` | Company/client account | WHMCS clients |
| `identity` | Contact point (email, slack_user, discord_user) | WHMCS contacts, Slack users, Discord members |
| `conversation` | Thread or channel | IMAP threads, Slack channels, Discord channels |
| `message` | Individual message in a conversation | IMAP, Slack, Discord, WHMCS tickets |
| `activity` | Summary event (one per day per channel, one per ticket thread) | All sources |

Each item sent to contact-monitor has:
```json
{
  "idempotency_key": "sha256(type:slug:itemType:externalId:payloadHash)",
  "type": "account|identity|conversation|message|activity",
  "action": "upsert",
  "system_type": "whmcs|imap|slack|discord|metricscube",
  "system_slug": "my-instance",
  "external_id": "12345",
  "payload_hash": "sha256(payload)",
  "payload": { ... }
}
```

Batches are sent as `POST /api/ingest/batch` with `X-Ingest-Secret` header.
`ContactMonitorClient` retries 3 times on 5xx with 2s / 4s backoff. 401/422 abort immediately.

---

## Normalizer Pattern

Each normalizer reads from `source_*` tables using a cursor (`sinceAt`) from `import_checkpoints`:

```php
class XxxNormalizer {
    public function normalize(?string $sinceAt, callable $log): \Generator {
        DB::table('source_xxx')->where('updated_at', '>', $sinceAt)->cursor()
            ->each(function ($row) {
                yield ['item' => ContactMonitorClient::buildItem(...), 'updated_at' => $row->updated_at];
            });
    }
}
```

`ContactMonitorExporter::exportConnection()` calls the right normalizer, collects 250 items, calls `sendBatch()`, then updates the export cursor.

---

## Checkpoint Keys

Import cursors are stored in `import_checkpoints` keyed by:
- WHMCS: `whmcs_api | {system} | clients|contacts|services|tickets`
- Gmail: `gmail | {system} | messages`
- IMAP: `imap | {system} | {mailbox}`
- Discord: `discord | messages | {system} | {channel_id}`
- Slack: `slack | messages | {system} | {channel_id}`

Export cursors: importer = `salesos_export`, entity = `{systemType}:{systemSlug}`

---

## Adding a New Connector Type

Four places to change:

1. **`app/Importers/NewType/ImportNewType.php`** — `run(callable $log): void`
2. **`app/Jobs/RunConnection.php`** — add case in `match($connection->type)` + private `runNewType()` method
3. **`app/Exporters/Normalizers/NewTypeNormalizer.php`** — `normalize(?string $sinceAt, callable $log): Generator`
4. **`app/Http/Controllers/Admin/ConnectionController.php`** — credential validation in `test()`
5. **`resources/views/admin/connections/form.blade.php`** — settings fields `x-show="type === 'newtype'"`

---

## Known Limitations

- **Gmail export** — import works, but export to contact-monitor is not implemented
- **Deletions** — Discord/Slack only poll for new/updated messages; deletions are not detected
- **Queue persistence** — jobs lost if worker container crashes mid-run; re-trigger manually
- **No rate limiting** — importer can hit API quotas on large runs; use `max_messages_per_run` to limit
- **Credentials plaintext** — `connections.settings` is not encrypted at rest (Google refresh tokens are the exception)
