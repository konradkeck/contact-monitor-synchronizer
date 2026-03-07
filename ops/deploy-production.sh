#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/srv/contact-monitor-synchronizer"
SSH_HOST="production"

echo "[1/5] Local: commit check"
git status --porcelain

echo "[2/5] Local: push"
git push

echo "[3/5] Remote: pull + build"
ssh "$SSH_HOST" "
    set -euo pipefail
    cd '$REPO_DIR'

    if [ ! -f .env ]; then
        echo ''
        echo 'ERROR: .env not found on the server.'
        echo 'Run on the server:'
        echo '  cp $REPO_DIR/.env.example $REPO_DIR/.env'
        echo '  nano $REPO_DIR/.env   # fill in APP_KEY, APP_URL, DB_*, ADMIN_PASSWORD'
        echo ''
        exit 1
    fi

    git fetch origin
    git reset --hard origin/main
    git clean -fd

    docker compose build --pull
    docker compose run --rm app composer install --no-dev --optimize-autoloader
    docker compose down
    docker compose up -d
"

echo "[4/5] Remote: migrate"
ssh "$SSH_HOST" "
    set -euo pipefail
    cd '$REPO_DIR'

    # Give DB a moment to be ready after compose up
    sleep 3

    docker compose exec -T app php artisan migrate --force
"

echo "[5/5] Remote: status"
ssh "$SSH_HOST" "cd '$REPO_DIR'; docker compose ps"
