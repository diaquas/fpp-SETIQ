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

# Speed: scripts/fseq_stats.py has a fast numpy path and a much slower
# pure-Python fallback. Install numpy if it's missing so sequence-stat
# scans use the fast path. Best-effort — never fail the install over it.
if command -v python3 > /dev/null 2>&1 && ! python3 -c 'import numpy' > /dev/null 2>&1; then
    echo "Installing python3-numpy for faster sequence stats (best-effort)..."
    if command -v apt-get > /dev/null 2>&1 \
       && DEBIAN_FRONTEND=noninteractive apt-get install -y python3-numpy > /dev/null 2>&1; then
        echo "numpy installed via apt."
    elif command -v pip3 > /dev/null 2>&1 \
         && pip3 install --break-system-packages numpy > /dev/null 2>&1; then
        echo "numpy installed via pip."
    else
        echo "Couldn't auto-install numpy; stats will use the slower fallback."
    fi
fi

# Decode: scripts/fseq_stats.py needs a zstd decoder (the `zstandard` Python
# module OR the `zstd` CLI) to read zstd-compressed renders. xLights/FPP v2
# renders default to zstd, so on a box with neither, EVERY sequence comes back
# with no stats (cues/colors/props all blank). Install a decoder if missing.
if command -v python3 > /dev/null 2>&1 \
   && ! python3 -c 'import zstandard' > /dev/null 2>&1 \
   && ! command -v zstd > /dev/null 2>&1; then
    echo "Installing a zstd decoder for sequence stats (best-effort)..."
    if command -v apt-get > /dev/null 2>&1 \
       && DEBIAN_FRONTEND=noninteractive apt-get install -y zstd > /dev/null 2>&1; then
        echo "zstd CLI installed via apt."
    elif command -v pip3 > /dev/null 2>&1 \
         && pip3 install --break-system-packages zstandard > /dev/null 2>&1; then
        echo "zstandard installed via pip."
    else
        echo "Couldn't auto-install a zstd decoder; zstd-compressed sequences will report no stats."
    fi
fi

echo "fpp-SETIQ installed. Open Content Setup -> SET:IQ / REQ:IQ."
exit 0
