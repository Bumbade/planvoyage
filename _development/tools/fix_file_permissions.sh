#!/usr/bin/env bash
# tools/fix_file_permissions.sh
# Inspect and optionally fix file ownership/permissions for a PlanVoyage deployment
# Usage:
#   sudo bash tools/fix_file_permissions.sh /var/www/html/Allgemein/planvoyage_V2 --fix
#   sudo bash tools/fix_file_permissions.sh /var/www/html/Allgemein/planvoyage_V2        # dry-run (inspect only)

set -euo pipefail

TARGET=${1:-/var/www/html/Allgemein/planvoyage_V2}
shift || true
DO_FIX=0
OWNER="www-data:www-data"
VERBOSE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --fix) DO_FIX=1; shift ;;
    --owner) OWNER="$2"; shift 2 ;;
    --verbose) VERBOSE=1; shift ;;
    -h|--help) echo "Usage: sudo bash tools/fix_file_permissions.sh <path> [--fix] [--owner user:group] [--verbose]"; exit 0 ;;
    *) shift ;;
  esac
done

if [[ ! -d "$TARGET" ]]; then
  echo "Target path does not exist: $TARGET" >&2
  exit 2
fi

echo "Running file-permissions check on: $TARGET"
echo "Current user: $(id -u -n) (uid=$(id -u) gid=$(id -g))"
echo "Intended owner when fixing: $OWNER"
echo "Mode: $( [[ $DO_FIX -eq 1 ]] && echo 'fix' || echo 'inspect only' )"
echo

echo "Disk usage summary:"; df -h "$TARGET" || true
echo

echo "Top-level listing:"; ls -la "$TARGET" || true
echo

echo "Files not writable by owner (first 200):"
find "$TARGET" -type f ! -perm -u+w -print | head -n 200 || true
echo

echo "Files not owned by $OWNER (first 200):"
find "$TARGET" -maxdepth 4 \( -type f -o -type d \) ! -user "${OWNER%%:*}" -print | head -n 200 || true
echo

echo "Files/directories with immutable flag (lsattr) (first 200):"
if command -v lsattr >/dev/null 2>&1; then
  lsattr -R "$TARGET" 2>/dev/null | grep -n 'i' || echo "(none found or no permission)"
else
  echo "lsattr not available; skipping immutable check"
fi
echo

echo "Recent modification times for logs/ and src/logs (if present):"
for d in "$TARGET/logs" "$TARGET/src/logs"; do
  if [[ -d "$d" ]]; then
    echo "Listing: $d"; ls -la --time-style=long-iso "$d" | head -n 200 || true
  fi
done
echo

if [[ $DO_FIX -eq 1 ]]; then
  echo "-- FIX MODE: applying conservative fixes --"
  echo "1) Removing immutable flag where present (requires root)"
  if command -v chattr >/dev/null 2>&1; then
    find "$TARGET" -maxdepth 6 -exec lsattr -d {} + 2>/dev/null | awk '/i/ {print $2}' | while read -r f; do
      if [[ -e "$f" ]]; then
        echo "Removing immutable: $f"
        chattr -i "$f" || true
      fi
    done
  else
    echo "chattr not available; skipping immutable removal"
  fi

  echo "2) Take ownership of files (recursive) -> $OWNER"
  chown -R $OWNER "$TARGET" || echo "chown failed (you may need sudo)"

  echo "3) Set directory and file permissions conservatively"
  # Directories 2755 (rwxr-sr-x) so webserver can create files in subdirs if owner is www-data
  find "$TARGET" -type d -exec chmod 2755 {} + || true
  # Files 0644
  find "$TARGET" -type f -exec chmod 0644 {} + || true

  echo "4) Ensure logs and tmp are writable by owner (www-data)"
  for d in "$TARGET/logs" "$TARGET/src/logs" "$TARGET/tmp" "$TARGET/src/tmp"; do
    if [[ -d "$d" ]]; then
      chmod -R 770 "$d" || true
      chown -R $OWNER "$d" || true
      echo "Adjusted: $d"
    fi
  done

  echo "Fixes applied. Re-run this script without --fix to re-inspect.";
else
  echo "Run again with --fix to apply conservative ownership/permission fixes. Example:";
  echo "  sudo bash tools/fix_file_permissions.sh $TARGET --fix --owner www-data:www-data"
fi

echo "Done. If files still appear missing in your editor, check that your editor isn't filtering hidden files and that any network share/OneDrive isn't interfering."

exit 0
