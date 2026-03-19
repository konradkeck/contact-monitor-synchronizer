# Contact Monitor Synchronizer

The Synchronizer pulls data from your external tools (WHMCS, Slack, Discord, email, Gmail) and pushes it into Contact Monitor. It runs in the background — you don't interact with it directly, you manage it from inside Contact Monitor.

---

## Requirements

- A Linux server with **Docker** installed
- A running **Contact Monitor** instance (the main app)

---

## Installation

The easiest way to install the Synchronizer is through the **Contact Monitor Setup Wizard** — it generates a ready-to-run install command for you.

### Step 1 — Open the wizard in Contact Monitor

Go to **Configuration → Synchronizer → Servers → New Server → Configure New Server**.

You'll see a one-liner install command. Copy it.

### Step 2 — Run it on your server

Paste and run the command in your server terminal. It will:

- Download the Synchronizer code
- Set up all configuration automatically
- Start Docker containers
- Register with your Contact Monitor instance

The whole process takes 3–5 minutes.

### Step 3 — Done

Go back to Contact Monitor. The wizard will detect the Synchronizer registered successfully and take you to the connections setup.

---

## Adding integrations

After installation, set up your data sources in Contact Monitor under **Configuration → Synchronizer → Connections → New Connection**.

**WHMCS** — requires the Contact Monitor for WHMCS addon installed on your WHMCS instance.
- Base URL: your WHMCS address (e.g. `https://billing.example.com`)
- API Token: from the WHMCS addon settings

**Slack** — requires a Slack bot:
1. Go to [api.slack.com/apps](https://api.slack.com/apps) → Create New App
2. Add bot scopes: `channels:read`, `channels:history`, `groups:read`, `groups:history`, `files:read`
3. Install to workspace, copy the Bot Token (`xoxb-...`)
4. For private channels: `/invite @your-bot-name` inside each channel

**Discord** — requires a Discord bot:
1. Go to [discord.com/developers/applications](https://discord.com/developers/applications) → New Application → Bot
2. Enable **Message Content Intent** under Privileged Gateway Intents
3. Invite bot with Read Messages + Read Message History permissions
4. Paste the bot token into the connection form

**Email (IMAP)** — any standard mailbox:
- Host, port (993 for SSL), username, password
- Common providers: Gmail IMAP, Outlook, any hosting mailbox

**Gmail** — requires an OAuth app in Google Cloud Console and a public HTTPS URL. See the [SSL section](#ssl-https) — Gmail OAuth won't work without HTTPS.

---

## Running your first import

After adding a connection:

1. Go to **Configuration → Synchronizer → Connections**
2. Click **▶ Run → Full** on your connection
3. A log popup opens — you can watch the import live
4. The first full import can take a few minutes to hours depending on data volume

After that, scheduled imports run automatically based on the schedule you configured.

---

## Updating

Run this on your server from the `contact-monitor-synchronizer` directory:

```bash
git pull
docker compose build --pull
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose restart app worker scheduler
```

Your data and connections are not affected.

---

## SSL (HTTPS)

SSL is required if you want to use **Gmail OAuth** (Google requires a public HTTPS callback URL). For all other integrations it's optional.

The simplest approach is **Caddy** — it gets SSL certificates automatically.

### Option A — Caddy (recommended)

**1. Install Caddy:**
```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install caddy
```

**2. Create `/etc/caddy/Caddyfile`:**
```
your-synchronizer-domain.com {
    reverse_proxy localhost:8080
}
```

**3. Start Caddy:**
```bash
sudo systemctl enable --now caddy
```

**4. Update `.env` in the synchronizer directory:**
```env
APP_URL=https://your-synchronizer-domain.com
```

Then restart: `docker compose restart app`

---

### Option B — Cloudflare Tunnel (no domain required, good for Gmail OAuth)

Cloudflare Tunnel exposes the synchronizer publicly via HTTPS without opening firewall ports or needing a domain.

```bash
# Install cloudflared
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o cloudflared
chmod +x cloudflared && sudo mv cloudflared /usr/local/bin/

# Log in
cloudflared tunnel login

# Create tunnel
cloudflared tunnel create contact-monitor-synchronizer

# Create config at ~/.cloudflared/config.yml:
# tunnel: <TUNNEL_ID>
# credentials-file: /home/<you>/.cloudflared/<TUNNEL_ID>.json
# ingress:
#   - hostname: sync.your-domain.com
#     service: http://localhost:8080
#   - service: http_status:404

# Route DNS
cloudflared tunnel route dns contact-monitor-synchronizer sync.your-domain.com

# Run (or install as a system service)
cloudflared tunnel run contact-monitor-synchronizer
```

Update `.env`:
```env
APP_URL=https://sync.your-domain.com
```

---

## Troubleshooting

**Synchronizer not appearing in Contact Monitor after install**
- Check that the install script completed without errors
- Check containers are running: `docker compose ps` — `app`, `db`, `worker`, `scheduler` should all show `Up`

**Data not arriving in Contact Monitor**
```bash
docker compose logs -f worker
```
Look for errors. Common cause: `CONTACT_MONITOR_INGEST_URL` can't be reached from inside the container.

Test connectivity:
```bash
docker exec contact-monitor-synchronizer_app curl http://host.docker.internal:8090/api/ingest/batch
# Should return 401, not "connection refused"
```

**Runs stuck in "running" state**

Worker crashed mid-job. Go to **Configuration → Synchronizer → Connections** and use the Kill All button, or run:
```bash
docker compose restart worker
```

**App won't start**
```bash
docker compose logs app --tail=50
```

**Restart everything**
```bash
docker compose restart
```
