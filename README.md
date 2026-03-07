# Contact Monitor Synchronizer — Data Importer

Laravel-based data importer. Pulls raw records from WHMCS, Gmail (via OAuth), IMAP mailboxes, and MetricsCube into local `source_*` tables with cursor-based checkpointing so imports can be interrupted and resumed. A full admin panel provides connection management, real-time job logs, and data stats.

---

## Table of Contents

- [Step-by-step configuration](#step-by-step-configuration)
- [Architecture overview](#architecture-overview)
- [Admin panel](#admin-panel)
  - [Authentication](#authentication)
  - [Connections](#connections)
  - [Real-time job logs](#real-time-job-logs)
  - [Scheduling](#scheduling)
  - [Data Stats](#data-stats)
- [WHMCS import](#whmcs-import)
- [Google OAuth + Gmail import](#google-oauth--gmail-import)
  - [Cloudflare Tunnel setup (required for OAuth callback)](#cloudflare-tunnel-setup-required-for-oauth-callback)
- [IMAP import](#imap-import)
- [MetricsCube import](#metricscube-import)
- [Discord import](#discord-import)
- [Slack import](#slack-import)
- [Adding a new connector type](#adding-a-new-connector-type)

---

## Step-by-step configuration

Quick checklist for standing this up from scratch. Each step links to the detailed section below.

**1. Start the stack**
```bash
docker compose up -d
```
Builds and starts four services: `app` (PHP + web server on port 8080), `db` (PostgreSQL on 5433), `worker` (queue consumer), `scheduler` (runs cron every minute).

**2. Configure `.env`**

Copy the example and fill in at minimum:
```bash
cp .env.example .env
```

Required values:
```env
APP_KEY=          # generate with: php artisan key:generate
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=contact-monitor-synchronizer
DB_USERNAME=contact-monitor-synchronizer
DB_PASSWORD=contact-monitor-synchronizer

SESSION_DRIVER=database
CACHE_STORE=database

ADMIN_PASSWORD=choose_a_strong_password
```

**3. Generate app key**
```bash
docker compose exec app php artisan key:generate
```

**4. Run migrations**
```bash
docker compose exec app php artisan migrate
```

**5. Open the admin panel**

Navigate to `http://localhost:8080/admin` — log in with the password from `ADMIN_PASSWORD`.

**6. Create connections in the admin panel**

Go to **Connections → New Connection**. Choose a type, enter the display name (the system slug is auto-generated from it), fill in all credentials directly in the form, configure the schedule if needed, and save.

All credentials (API tokens, passwords, OAuth client secrets, etc.) are stored in the database — no additional `.env` entries are needed per connection.

From the connections list you can:
- **▶ Run** — trigger a job immediately and watch live logs
- **Logs** — view logs from the last run
- **Edit / Delete** — manage the connection

**7. (Gmail only) Expose the app via Cloudflare Tunnel**

See [Cloudflare Tunnel setup](#cloudflare-tunnel-setup-required-for-oauth-callback). Required for the Google OAuth callback URL.

**8. (Gmail only) Authorize a Gmail account**

After saving the Gmail connection, open **Edit**. The OAuth status card shows an **▶ Authorize with Google** button — click it, complete the OAuth flow, and the status will switch to "Token active".

---

## Architecture overview

**Importers** live in `app/Importers/{Type}/` and share a single interface:
```php
class ImportXxx {
    public function run(callable $log): void { ... }
}
```
The `$log` callable receives `(string $message, string $level = 'info')` and is provided by the job or the Artisan command wrapper.

**Each importer:**
1. Fetches records from the external source in pages or batches.
2. Upserts each record into a `source_*` table using a SHA-256 `row_hash` for change detection (insert / update / skip).
3. Persists a cursor in `import_checkpoints` so the next run continues where the previous one stopped.

**Jobs and scheduling:**

`RunConnection` is a queued job that wraps any importer type. When triggered (manually or by cron), it:
1. Reads connection credentials from the database and injects them into the process environment so importers can access them.
2. Creates a `connection_runs` row.
3. Buffers log lines and flushes them to a PostgreSQL JSONB column every 10 lines or 3 seconds.
4. Marks the run `completed` or `failed` when done.

The `connections:run-scheduled` Artisan command runs every minute (via the `scheduler` container), checks each connection's cron expression, and dispatches `RunConnection` jobs for those that are due.

Real-time log streaming uses **Server-Sent Events (SSE)** — the browser polls a DB-backed endpoint, no WebSocket server required.

**Database tables:**

| Table | Description |
|---|---|
| `connections` | Connection definitions (type, slug, settings JSON, schedule) |
| `connection_runs` | Run history: status, timestamps, log_lines (JSONB), error |
| `source_whmcs_clients` | Raw WHMCS client records |
| `source_whmcs_contacts` | Raw WHMCS contact records |
| `source_whmcs_services` | Raw WHMCS service records |
| `source_whmcs_tickets` | Raw WHMCS ticket/message records |
| `source_gmail_messages` | Raw Gmail messages (full API payload JSON) |
| `source_imap_messages` | Raw IMAP messages (headers + text/html body parts) |
| `source_metricscube_client_activities` | MetricsCube client activity records |
| `source_discord_channels` | Discord channel metadata per guild |
| `source_discord_messages` | Raw Discord messages |
| `source_discord_attachments` | Discord attachment metadata (no binaries) |
| `source_slack_channels` | Slack channel metadata |
| `source_slack_messages` | Raw Slack messages (incl. thread replies) |
| `source_slack_files` | Slack file metadata shared in channels (no binaries) |
| `oauth_google_tokens` | Encrypted Google refresh tokens per system+email |
| `import_checkpoints` | Cursor state for all importers |

---

## Admin panel

**URL:** `http://localhost:8080/admin`

### Authentication

Session-based login with a single password configured in `.env`:

```env
ADMIN_PASSWORD=your_password
```

If `ADMIN_PASSWORD` is not set, the default is `admin` — change it before exposing the app publicly.

To update the password: edit `.env`, then log out and log back in (no container restart needed).

### Connections

The **Connections** page lists all configured connections with:
- Type badge (WHMCS / Gmail / IMAP / MetricsCube)
- System slug
- Schedule (cron expression + human-readable label + next run time)
- Last run time and duration
- Live status badge (pending / running / completed / failed) — auto-updates via polling

**Actions per connection:**
- **▶ Run** — dispatches a job immediately; opens a live log popup with real-time streaming
- **Logs** — opens the log popup with the last run's output (automatically polls if the run is still active)
- **Edit** — full settings form
- **⧉ Dupe** — duplicate the connection (creates a copy with ` (copy)` suffix; update name and slug before use)
- **■ Stop** — cancel a running/pending job (visible only when active)
- **✕ Delete** — removes connection and all run history

**Connection form fields:**

*All types:*
- Display name (system slug is auto-generated as you type)
- Active toggle, Schedule (toggle + cron presets + manual input)

*WHMCS:*
- Base URL, API Token
- Entities to import (clients / contacts / services / tickets)

*Gmail:*
- Google OAuth Client ID and Client Secret
- OAuth Callback URL (read-only, shown as soon as you enter the slug — copy this into Google Cloud Console)
- Subject email (the Gmail account to import)
- Search query (optional, e.g. `in:inbox`)
- Excluded labels (one per line)
- Page size, max pages
- OAuth token status card with **▶ Authorize with Google** + **Copy link** buttons (edit mode only — use Copy link to share the auth URL when the OAuth flow must be completed from a different browser/device)

*IMAP:*
- Host, port, encryption
- Username and password
- Excluded mailboxes (one per line — all others are scanned)
- Batch size, max batches

*MetricsCube:*
- Linked WHMCS connection
- App Key and Connector Key

All forms include a **Test Connection** button that validates credentials before saving.

### Real-time job logs

When you click **▶ Run** or **Logs**, a popup opens showing structured log output. Running jobs stream new lines in real time via SSE. Completed/failed runs show the full historical log from the database.

Log lines are color-coded: white for info, yellow for warnings, red for errors.

### Scheduling

Each connection can have a cron-based schedule. The `scheduler` Docker service runs `connections:run-scheduled` every minute, which:
1. Checks each active connection's cron expression against the current time.
2. Skips connections that already have a pending or running job.
3. Dispatches a `RunConnection` job for those that are due.

Preset buttons in the form (every 15 min / hourly / daily / etc.) generate the correct cron expression. You can also type a custom expression.

MetricsCube connections do not have their own schedule — they always run alongside their linked WHMCS connection.

### Data Stats

The **Data Stats** page shows:
- Run summary cards: total runs (24h), completed, failed, currently running
- Per source table: total record count, records changed in last 24h, newest record age, per-system breakdown

---

## WHMCS import

### How it works

WHMCS is accessed via a custom addon API (`salesos_synch_api`) installed on the WHMCS instance. It exposes resources (`clients`, `contacts`, `services`, `tickets`) and supports cursor pagination via `after_id` (or `after_sent_at`+`after_ticket_id` for tickets).

Each importer:
- Pages through all records 1 000 at a time (200 for tickets).
- Upserts into the corresponding `source_whmcs_*` table.
- Saves a checkpoint after every page so interrupted runs resume cleanly.

The `whmcs:import-clients` command additionally pushes changed clients to **MetricsCube** after each upsert (requires a linked MetricsCube connection; gracefully skipped if not configured).

### Credentials

Configure via the admin panel connection form:
- **Base URL** — e.g. `https://your-whmcs.example.com`
- **API Token** — the Bearer token for the addon API

### Commands

```bash
# Clients  (cursor: after_id on clientid)
php artisan whmcs:import-clients salesos

# Contacts (cursor: after_id on contactid)
php artisan whmcs:import-contacts salesos

# Services (cursor: after_id on serviceid)
php artisan whmcs:import-services salesos

# Tickets  (cursor: after_sent_at + after_ticket_id on msg_id)
php artisan whmcs:import-tickets salesos
```

Replace `salesos` with your system slug. These commands are thin wrappers — the same logic runs when a job is dispatched from the admin panel.

### Resetting a checkpoint

To re-import from scratch, delete the relevant row from `import_checkpoints`:

```sql
DELETE FROM import_checkpoints
WHERE source_system = 'salesos'
  AND importer = 'whmcs_api'
  AND entity = 'clients';   -- or contacts / services / tickets
```

---

## Google OAuth + Gmail import

### How it works

1. Click **▶ Authorize with Google** in the Gmail connection edit page → a popup opens and redirects to Google's OAuth consent screen.
2. After consent, Google redirects to `/google/callback/{system}`.
3. The app exchanges the code for tokens and stores the encrypted `refresh_token` in `oauth_google_tokens` (keyed by `system` + `subject_email`).
4. `gmail:import-messages` uses the stored token to fetch messages via Gmail API (`messages.list` then `messages.get format=full` concurrently via `Http::pool()`) and upserts them into `source_gmail_messages`.

**Scopes requested:**
- `https://www.googleapis.com/auth/gmail.modify`
- `https://www.googleapis.com/auth/gmail.send`

Refresh tokens are encrypted at rest with Laravel `Crypt::encryptString` (requires `APP_KEY`).

### Each Gmail connection has its own callback URL

The callback URL includes the system slug (`/google/callback/{slug}`) so the app knows which connection to associate the token with. This allows multiple Gmail connections (different accounts or OAuth apps) to coexist — each with its own registered redirect URI in Google Cloud Console.

The callback URL is shown as a read-only field in the connection form as soon as you type the system slug. Copy it and register it in Google Cloud Console before running the OAuth flow.

### Step 1 — Create a Google Cloud OAuth app

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services** → **Credentials**.
2. Click **Create Credentials** → **OAuth client ID** → **Web application**.
3. Under **Authorised redirect URIs** add the callback URL shown in the connection form:
   ```
   https://oauth.your-subdomain.example.com/google/callback/{system}
   ```
   Replace `{system}` with your actual slug (e.g. `salesos`).
4. Copy the **Client ID** and **Client Secret** — enter them in the connection form.
5. Enable the **Gmail API** under **APIs & Services** → **Enabled APIs**.

> The callback URL must be publicly accessible over HTTPS. See [Cloudflare Tunnel setup](#cloudflare-tunnel-setup-required-for-oauth-callback) below.

### Step 2 — Save credentials and authorize

1. Create (or edit) the Gmail connection in the admin panel. Enter the Client ID, Client Secret, and the Gmail account email.
2. Save the connection.
3. Open **Edit** — the OAuth status card appears at the bottom of the Gmail settings.
4. Click **▶ Authorize with Google**, complete the consent flow in the popup, and wait for the status to switch to "Token active".

**"No refresh_token received" error:** the account was previously authorized. Go to [myaccount.google.com/permissions](https://myaccount.google.com/permissions), remove the app, then authorize again.

**Re-authorizing / rotating tokens:** click **Re-authorize** on the OAuth status card. If a token for that system+email already exists it will be updated in place.

### Step 3 — Import Gmail messages

```bash
# All messages
php artisan gmail:import-messages salesos inbox@example.com

# With Gmail search query
php artisan gmail:import-messages salesos inbox@example.com --query="in:inbox"

# Limit to 5 pages (500 messages at default page size)
php artisan gmail:import-messages salesos inbox@example.com --max-pages=5

# Custom page size
php artisan gmail:import-messages salesos inbox@example.com --page-size=50

# Reset checkpoint and re-import from scratch
php artisan gmail:import-messages salesos inbox@example.com --reset-checkpoint
```

| Option | Default | Description |
|---|---|---|
| `--query` | _(all mail)_ | Gmail search query, e.g. `in:inbox after:2024/01/01` |
| `--page-size` | `100` | Messages per page (max 500) |
| `--max-pages` | `0` (unlimited) | Stop after N pages |
| `--reset-checkpoint` | — | Delete saved cursor and restart from the beginning |

---

## Cloudflare Tunnel setup (required for OAuth callback)

Google OAuth requires a publicly accessible HTTPS callback URL. Cloudflare Tunnel exposes your local app without a static IP or open firewall ports.

### Prerequisites

- A domain managed by Cloudflare.
- [`cloudflared`](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/) installed on the machine running the app.

### One-time setup

**1. Log in:**
```bash
cloudflared tunnel login
```
A browser window opens — select your domain.

**2. Create a named tunnel:**
```bash
cloudflared tunnel create contact-monitor-synchronizer-oauth
```
Note the **Tunnel ID** (a UUID) printed in the output.

**3. Create the config file** at `~/.cloudflared/config.yml`:
```yaml
tunnel: <TUNNEL_ID>
credentials-file: /home/<you>/.cloudflared/<TUNNEL_ID>.json

ingress:
  - hostname: oauth.your-subdomain.example.com
    service: http://localhost:8080
  - service: http_status:404
```

Replace `<TUNNEL_ID>`, the hostname, and the local port (8080 matches the docker-compose port mapping).

**4. Route DNS:**
```bash
cloudflared tunnel route dns contact-monitor-synchronizer-oauth oauth.your-subdomain.example.com
```
This creates a CNAME in Cloudflare DNS automatically.

**5. Start the tunnel:**
```bash
cloudflared tunnel run contact-monitor-synchronizer-oauth
```
Keep this running while going through the OAuth flow.

### Running the tunnel as a systemd service (optional)

```bash
sudo cloudflared service install
sudo systemctl enable cloudflared
sudo systemctl start cloudflared
```

### Laravel settings when behind the tunnel

Add to `.env`:
```env
APP_URL=https://oauth.your-subdomain.example.com
SESSION_DOMAIN=oauth.your-subdomain.example.com
```

### Verify

```bash
curl -I https://oauth.your-subdomain.example.com/google/auth/salesos
# Expect: HTTP/2 302 → Location: accounts.google.com/...
```

---

## IMAP import

### How it works

The importer:

1. Connects to the IMAP server using PHP's built-in `imap` extension.
2. Lists all mailboxes on the server, skipping any in the excluded list.
3. For each mailbox, fetches messages with UID greater than the last checkpoint (or all messages on the first run).
4. For each message, fetches headers and extracts `text/plain` and `text/html` body parts (handles base64, quoted-printable, and charset conversion).
5. Upserts into `source_imap_messages` by `(account, mailbox, uid)` using SHA-256 `row_hash` for change detection.
6. Saves a checkpoint (`last_uid`) after each batch.

Attachments are not stored — only headers and text/HTML body parts.

### PHP imap extension

The `imap` extension is included in the project's `Dockerfile` (pinned to `php:8.3-cli-bookworm`). No extra setup needed when using Docker Compose.

If running PHP outside Docker, install manually:
```bash
# Debian/Ubuntu
apt-get install -y libc-client-dev libkrb5-dev
docker-php-ext-configure imap --with-kerberos --with-imap-ssl
docker-php-ext-install imap
```

Verify: `php -m | grep imap`

### Credentials

Configure via the admin panel connection form:
- **Host**, **Port**, **Encryption** (ssl / tls / none)
- **Username** and **Password**
- **Excluded mailboxes** (one per line) — all other mailboxes are scanned

**Common port/encryption combinations:**

| Setup | Port | Encryption |
|---|---|---|
| IMAPS (implicit TLS) | 993 | `ssl` |
| STARTTLS | 143 | `tls` |
| No encryption (dev only) | 143 | `none` |

### Command

```bash
# Import all mailboxes (excluding those configured in the form)
php artisan imap:import-messages office

# Process only 3 batches of 50 messages (useful for testing)
php artisan imap:import-messages office --batch-size=50 --max-batches=3

# Reset checkpoint and re-import from scratch
php artisan imap:import-messages office --reset-checkpoint
```

| Option | Default | Description |
|---|---|---|
| `--batch-size` | `100` | Messages per batch |
| `--max-batches` | `0` (unlimited) | Stop after N batches |
| `--reset-checkpoint` | — | Delete saved UID cursor and restart |

### Multiple accounts

Each account slug is independent. To import several accounts, create separate connections in the admin panel (or run the command once per slug):

```bash
php artisan imap:import-messages office
php artisan imap:import-messages support
php artisan imap:import-messages billing
```

---

## Discord import

### How it works

The importer polls the Discord REST API (no gateway, no webhooks). On each run it:

1. Lists all guilds the bot can see (`GET /users/@me/guilds`), optionally filtered by the guild allowlist.
2. For each guild, fetches text channels (`GET /guilds/{id}/channels`), optionally filtered by the channel allowlist.
3. Upserts channel metadata into `source_discord_channels`.
4. For each channel, fetches messages:
   - **Full mode**: paginates backward from the newest message to the oldest using `before=` cursor.
   - **Partial mode**: fetches only messages newer than the last checkpoint using `after=` cursor.
5. Upserts messages into `source_discord_messages` and attachments (metadata only, no binaries) into `source_discord_attachments`.
6. Saves a per-channel cursor in `import_checkpoints`: `discord|messages|{system}|{channel_id}`.

> **Note:** Polling cannot detect message deletions in real time. The `is_deleted` field is reserved for future use (e.g. via the Audit Log API).

### Step 1 — Create a Discord Application and bot

1. Go to the [Discord Developer Portal](https://discord.com/developers/applications) and click **New Application**.
2. Under **Bot**, click **Add Bot** → confirm.
3. Copy the **Token** — you will paste it into the Contact Monitor Synchronizer connection form.
4. Under **Privileged Gateway Intents**, enable **Message Content Intent** (required to read message bodies).
5. Under **OAuth2 → URL Generator**, select scopes: `bot`. Select bot permissions:
   - **Read Messages / View Channels**
   - **Read Message History**
6. Copy the generated URL, open it in a browser, and select the server to add the bot to.

### Step 2 — Find guild and channel IDs

Discord IDs are not visible by default. Enable **Developer Mode** first:

1. In Discord, go to **User Settings → Advanced → Developer Mode** → toggle on.
2. Right-click a server name in the sidebar → **Copy Server ID** (this is the guild ID).
3. Right-click a channel name → **Copy Channel ID**.

Paste these into the allowlist fields in the connection form (one ID per line).

### Step 3 — Configure the connection in Contact Monitor Synchronizer

1. In the admin panel, go to **Connections → New Connection**.
2. Select type **Discord**, enter a display name and system slug.
3. Paste the **Bot Token**.
4. Optionally restrict to specific guilds or channels using the allowlist fields (one ID per line).
5. Save the connection. Click **Test Connection** to verify the token.

### Step 4 — Run the import

- Click **▶ Run → Full** for the first import (fetches complete history).
- Subsequent scheduled runs use **Partial** mode and only fetch new messages.

### Resetting a checkpoint

To re-import a channel from scratch, delete its checkpoint row:

```sql
DELETE FROM import_checkpoints
WHERE cursor_meta::text LIKE '%discord|messages|your-slug|channel-id%';
```

Or wipe all checkpoints for a system:

```sql
DELETE FROM import_checkpoints
WHERE cursor_meta::text LIKE '%discord|messages|your-slug|%';
```

---

## MetricsCube import

### How it works

MetricsCube does not run as a standalone connection — it is linked to a WHMCS connection and runs alongside it. When a WHMCS job completes, client records are pushed to the MetricsCube API.

### Credentials

Configure via the admin panel:
- **Linked WHMCS connection** — select which WHMCS connection triggers this one
- **App Key** and **Connector Key**

MetricsCube connections do not have their own schedule or run button — they appear in the run history mirroring the linked WHMCS run.

---

## Slack import

### How it works

The importer polls the Slack Web API (no Events API, no Socket Mode, no webhooks). On each run it:

1. Lists all channels the bot is a member of (`GET /conversations.list`), optionally filtered by the channel allowlist.
2. Upserts channel metadata into `source_slack_channels`.
3. For each channel, fetches messages via `GET /conversations.history`:
   - **Full mode**: pages through all history from newest to oldest.
   - **Partial mode**: uses `oldest={last_ts}` to fetch only messages newer than the last checkpoint.
4. Upserts messages into `source_slack_messages` and file metadata into `source_slack_files` (no binary download).
5. If **Import thread replies** is enabled, fetches replies for threaded messages via `GET /conversations.replies`.
6. Saves per-channel cursors in `import_checkpoints`: `slack|messages|{system}|{channel_id}`.

> **Note:** For private channels, the bot must be manually invited to the channel before it can read history.

### Step 1 — Create a Slack App

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and click **Create New App → From scratch**.
2. Name the app, select your workspace, click **Create App**.
3. Under **OAuth & Permissions → Scopes → Bot Token Scopes**, add:
   - `channels:read` — list public channels
   - `channels:history` — read public channel history
   - `groups:read` — list private channels the bot is in
   - `groups:history` — read private channel history
   - `files:read` — access file metadata shared in channels
4. Click **Install to Workspace** → **Allow**.
5. Copy the **Bot User OAuth Token** (starts with `xoxb-`).

### Step 2 — Invite the bot to private channels

For each private channel you want to import:
```
/invite @your-bot-name
```

The bot will only see channels it has been invited to. Public channels are visible automatically once the bot is installed to the workspace.

### Step 3 — Configure the connection in Contact Monitor Synchronizer

1. In the admin panel, go to **Connections → New Connection**.
2. Select type **Slack**, enter a display name and system slug.
3. Paste the **Bot Token** (`xoxb-...`).
4. Optionally restrict to specific channels using the channel allowlist (one channel ID per line — find IDs in Slack via **channel settings → copy link → extract the C... part**).
5. Save and click **Test Connection** to verify.

### Step 4 — Run the import

- Click **▶ Run → Full** for the first run.
- Configure a schedule for ongoing partial imports.

### Finding channel IDs

The easiest way to find a Slack channel ID:
- Open the channel in Slack → click the channel name at the top → scroll to the bottom of the details panel — the ID (e.g. `C01234ABCDE`) is shown there.
- Or: right-click the channel in the sidebar → **Copy link** → the ID is the last segment of the URL.

### Resetting a checkpoint

To re-import a channel from scratch, delete its checkpoint row:

```sql
DELETE FROM import_checkpoints
WHERE cursor_meta::text LIKE '%slack|messages|your-slug|C01234ABCDE%';
```

Or wipe all checkpoints for a system:

```sql
DELETE FROM import_checkpoints
WHERE cursor_meta::text LIKE '%slack|messages|your-slug|%';
```

---

## Adding a new connector type

Each connector type requires changes in four places:

**1. `app/Importers/NewType/ImportNewType.php`** — the importer class:
```php
class ImportNewType {
    public function __construct(private string $system) {}

    public function run(callable $log): void {
        $log('Starting…');
        // fetch → upsert → checkpoint
    }
}
```

**2. `app/Jobs/RunConnection.php`** — add a case in `runImporter()` and inject credentials in `injectEnv()`:
```php
match ($connection->type) {
    'whmcs'   => $this->runWhmcs($connection, $log),
    'gmail'   => $this->runGmail($connection, $log),
    'imap'    => $this->runImap($connection, $log),
    'newtype' => $this->runNewType($connection, $log), // add this
    default   => throw new \RuntimeException("Unknown type: {$connection->type}"),
};
```

**3. `app/Http/Controllers/Admin/ConnectionController.php`** — add a branch in `test()` to validate credentials and in `validateConnection()` to build the settings array.

**4. `resources/views/admin/connections/form.blade.php`** — add a settings section:
```html
<div x-show="type === 'newtype'" ...>
    <!-- type-specific fields -->
</div>
```

Optionally add `.badge-newtype` CSS in `resources/views/admin/layout.blade.php`.
