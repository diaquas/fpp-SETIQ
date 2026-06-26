#!/bin/bash
# Remove the REQ:IQ listener watchdog cron and stop the listener.
crontab -l 2>/dev/null | grep -v 'fpp-SETIQ/scripts/watchdog.sh' | crontab - 2>/dev/null
pkill -f reqiq_listener.php 2>/dev/null
echo "fpp-SETIQ uninstalled."
exit 0
