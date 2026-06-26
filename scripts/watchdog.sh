#!/bin/bash
# REQ:IQ listener watchdog — relaunches the listener if it has died while
# REQ:IQ is still enabled. Run from cron every minute (installed by
# fpp_install.sh). The listener's own pid-file singleton guard prevents a
# double launch, so running this often is safe.
#
# Without this, the listener only ever starts on fppd boot (postStart.sh) or
# plugin install/upgrade — so a crash, an OOM kill, or any mid-run exit left it
# dead until the next FPP restart, and the show read "offline" in REQ:IQ even
# though the box was up.

FLAG=/home/fpp/media/config/fpp-SETIQ.reqiq
LOG=/home/fpp/media/logs/fpp-SETIQ-reqiq.log
DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [ -f "$FLAG" ] && grep -q '^enabled=1' "$FLAG"; then
    if ! pgrep -f reqiq_listener.php > /dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] watchdog: listener down — relaunching" >> "$LOG"
        setsid nohup /usr/bin/php "$DIR/reqiq_listener.php" < /dev/null >> "$LOG" 2>&1 &
    fi
fi
