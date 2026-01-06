#!/bin/sh
# Ensure Overpass quick proxy cache + log exist and have correct ownership
set -e
ROOT="/var/www/html/Allgemein/planvoyage_V2"
CACHE_DIR="$ROOT/tmp/overpass_quick_cache"
LOG_FILE="$ROOT/logs/overpass_quick.log"
WEB_USER="www-data"

mkdir -p "$CACHE_DIR"
if [ ! -f "$LOG_FILE" ]; then
  mkdir -p "$(dirname "$LOG_FILE")"
  touch "$LOG_FILE"
fi
chown -R "$WEB_USER":"$WEB_USER" "$CACHE_DIR"
chown "$WEB_USER":"$WEB_USER" "$LOG_FILE"
chmod 0755 "$CACHE_DIR"
chmod 0644 "$LOG_FILE"

echo "Cache dir: $CACHE_DIR"
echo "Log file: $LOG_FILE"
echo "Owned by: $WEB_USER"
