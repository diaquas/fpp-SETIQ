#!/bin/bash
# fpp-SETIQ install hook. php-curl ships with FPP; nothing to build.
# Make sure the lifecycle hooks are executable regardless of how the
# plugin landed on disk, and (upgrade path) start the REQ:IQ listener
# right away if it was enabled before the update.

cd "$(dirname "$0")/.." || exit 0
chmod +x scripts/*.sh 2>/dev/null

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    if ! pgrep -f reqiq_listener.php > /dev/null 2>&1; then
        echo "REQ:IQ was enabled — starting the listener."
        setsid nohup /usr/bin/php "$(pwd)/reqiq_listener.php" < /dev/null >> "$LOG" 2>&1 &
    fi
fi

echo "fpp-SETIQ installed. Open Content Setup -> SET:IQ / REQ:IQ."
exit 0
