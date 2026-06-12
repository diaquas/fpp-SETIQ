#!/bin/bash
# Runs when fppd stops: stop the REQ:IQ listener (it restarts with fppd
# via postStart.sh while enabled).

PIDFILE=/tmp/fpp-SETIQ-reqiq.pid

if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE")
    if [ -n "$PID" ] && [ -d "/proc/$PID" ]; then
        kill "$PID"
    fi
    rm -f "$PIDFILE"
fi
