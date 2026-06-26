#!/bin/bash
# Runs when fppd starts: launch the REQ:IQ listener if it's enabled on
# the plugin's REQ:IQ page, and make sure the respawn watchdog cron is in
# place (self-heals an install that predates the watchdog).

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log
WATCHDOG=/home/fpp/media/plugins/fpp-SETIQ/scripts/watchdog.sh

# Ensure the once-a-minute respawn watchdog cron exists (idempotent).
if [ -x "$WATCHDOG" ] && ! crontab -l 2>/dev/null | grep -q 'fpp-SETIQ/scripts/watchdog.sh'; then
    ( crontab -l 2>/dev/null ; echo "* * * * * $WATCHDOG >/dev/null 2>&1" ) | crontab - 2>/dev/null
fi

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    if ! pgrep -f reqiq_listener.php > /dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] postStart: launching REQ:IQ listener" >> "$LOG"
        setsid nohup /usr/bin/php /home/fpp/media/plugins/fpp-SETIQ/reqiq_listener.php < /dev/null >> "$LOG" 2>&1 &
    fi
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] postStart: REQ:IQ disabled — listener not started" >> "$LOG"
fi
