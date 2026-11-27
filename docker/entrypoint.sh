#!/bin/sh
# Container entrypoint: binds Apache to the platform-provided $PORT and prepares persistent storage.
set -eu

# Railway (and most PaaS) inject PORT and route traffic/healthchecks to it. Default to 80 elsewhere.
PORT_VALUE="${PORT:-80}"
case "$PORT_VALUE" in
  ''|*[!0-9]*)
    echo "[entrypoint] Invalid PORT value '$PORT_VALUE'" >&2
    exit 1
    ;;
esac
export APACHE_LISTEN_PORT="$PORT_VALUE"

APP_DIR="/var/www/html"
UPLOAD_PUBLIC="$APP_DIR/public/uploads"
mkdir -p "$UPLOAD_PUBLIC/logos" "$APP_DIR/storage/uploads" "$APP_DIR/logs"

# Optional persistent volume (Railway: attach a Volume and set PERSIST_DIR to its mount path, e.g. /data).
# Private warranty attachments go to $PERSIST_DIR/uploads (outside the web root); public logos are
# linked from $PERSIST_DIR/logos. Without a volume, uploaded files are lost on every redeploy.
PERSIST="${PERSIST_DIR:-}"
if [ -n "$PERSIST" ]; then
  if [ -d "$PERSIST" ] && [ -w "$PERSIST" ]; then
    mkdir -p "$PERSIST/uploads" "$PERSIST/logos"
    chown -R www-data:www-data "$PERSIST/uploads" "$PERSIST/logos"
    chmod 750 "$PERSIST/uploads"
    rm -rf "$UPLOAD_PUBLIC/logos"
    ln -sfn "$PERSIST/logos" "$UPLOAD_PUBLIC/logos"
    export UPLOADS_DIR="${UPLOADS_DIR:-$PERSIST/uploads}"
    echo "[entrypoint] Persistent storage enabled at $PERSIST"
  else
    echo "[entrypoint] WARNING: PERSIST_DIR='$PERSIST' is not a writable directory; uploads are ephemeral." >&2
  fi
else
  echo "[entrypoint] NOTE: PERSIST_DIR is not set; uploaded files will not survive a redeploy." >&2
fi

chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/logs" "$UPLOAD_PUBLIC" 2>/dev/null || true
chmod 750 "$APP_DIR/storage/uploads" 2>/dev/null || true

exec "$@"
