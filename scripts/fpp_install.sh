#!/bin/bash
# fpp-SETIQ install hook. php-curl ships with FPP; nothing to build.
# Make sure the lifecycle hooks are executable regardless of how the
# plugin landed on disk, and (upgrade path) start the REQ:IQ listener
# right away if it was enabled before the update.

cd "$(dirname "$0")/.." || exit 0
chmod +x scripts/*.sh 2>/dev/null

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log
WATCHDOG="$(pwd)/scripts/watchdog.sh"

# Install a once-a-minute watchdog cron so the REQ:IQ listener is respawned if
# it ever dies (crash / OOM / mid-run exit). Without it the listener only
# (re)started on fppd boot, so a dead listener left the show "offline" in
# REQ:IQ until the next FPP restart. Idempotent: drop any prior line first.
( crontab -l 2>/dev/null | grep -v 'fpp-SETIQ/scripts/watchdog.sh' ; \
  echo "* * * * * $WATCHDOG >/dev/null 2>&1" ) | crontab - 2>/dev/null \
  && echo "REQ:IQ watchdog cron installed." \
  || echo "Could not install the watchdog cron (no crontab?) — listener still starts on boot."

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    if ! pgrep -f reqiq_listener.php > /dev/null 2>&1; then
        echo "REQ:IQ was enabled — starting the listener."
        setsid nohup /usr/bin/php "$(pwd)/reqiq_listener.php" < /dev/null >> "$LOG" 2>&1 &
    fi
fi

echo "fpp-SETIQ installed. Open Content Setup -> SET:IQ / REQ:IQ."
exit 0
