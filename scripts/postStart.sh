#!/bin/bash
# Runs when fppd starts: launch the REQ:IQ listener if it's enabled on
# the plugin's REQ:IQ page.

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    if ! pgrep -f reqiq_listener.php > /dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] postStart: launching REQ:IQ listener" >> "$LOG"
        setsid nohup /usr/bin/php /home/fpp/media/plugins/fpp-SETIQ/reqiq_listener.php < /dev/null >> "$LOG" 2>&1 &
    fi
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] postStart: REQ:IQ disabled — listener not started" >> "$LOG"
fi
