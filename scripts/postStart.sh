#!/bin/bash
# Runs when fppd starts: launch the REQ:IQ listener if it's enabled on
# the plugin's REQ:IQ page.

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    /usr/bin/php /home/fpp/media/plugins/fpp-SETIQ/reqiq_listener.php >> "$LOG" 2>&1 &
fi
