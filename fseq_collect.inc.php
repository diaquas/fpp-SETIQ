<?php
/**
 * Shared per-sequence .fseq stats collector.
 *
 * Used by BOTH the manual "Pull from SET:IQ" page (pull.php) and the REQ:IQ
 * listener daemon (reqiq_listener.php), so the heavy fseq_stats.py scan and
 * its on-box signature cache are computed once and shared. Pure functions —
 * including this file runs no top-level code, so the daemon can require it.
 */

if (!function_exists('setiq_fseq_path')) {
    /** Resolve a sequence name to its on-box .fseq path, or null. */
    function setiq_fseq_path($mediaDir, $name) {
        foreach (['sequences', 'Sequences'] as $sub) {
            $p = "$mediaDir/$sub/$name";
            if (is_file($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('setiq_collect_stats')) {
    /**
     * Per-sequence stats (lighting cues, top-3 colors, and run-length lit
     * channel ranges) computed on the box by scripts/fseq_stats.py from the
     * rendered .fseq. The box has no xLights layout (it lives in Studio IQ),
     * so prop attribution is NOT done here — the cloud reconciles the
     * `activity` runs against the uploaded layout. Cached by file signature
     * so unchanged sequences aren't re-scanned, with a wall-clock budget so a
     * first run on a big show never hangs the caller — anything not reached
     * this time is picked up (and cached) on the next call.
     *
     * $budgetSec bounds time spent on FRESH scans this call (cached entries
     * are always returned, instantly). The interactive Pull page passes the
     * full budget; the listener passes a small one (or 0 while a show is
     * playing) so a first-time scan never stalls live transport.
     */
    function setiq_collect_stats($names, $pluginDir, $cfgDir, $mediaDir, $budgetSec = 240) {
        $script = "$pluginDir/scripts/fseq_stats.py";
        if (!is_file($script)) return [];
        $py = trim((string) @shell_exec('command -v python3'));
        if ($py === '') return [];
        $hasTimeout = trim((string) @shell_exec('command -v timeout')) !== '';

        $cacheFile = "$cfgDir/fpp-SETIQ.stats-cache.json";
        $cache = [];
        if (is_file($cacheFile)) {
            $j = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($j)) $cache = $j;
        }

        @set_time_limit(0);
        $deadline = time() + max(0, (int) $budgetSec); // budget for fresh scans
        $out = [];
        $next = [];
        foreach ($names as $name) {
            $path = setiq_fseq_path($mediaDir, $name);
            if ($path === null) continue;
            $sig = @filemtime($path) . ':' . @filesize($path);

            if (isset($cache[$name]['sig'], $cache[$name]['stats'])
                && $cache[$name]['sig'] === $sig
                && is_array($cache[$name]['stats'])) {
                $next[$name] = $cache[$name];
                if ($cache[$name]['stats']) $out[$name] = $cache[$name]['stats'];
                continue;
            }

            if (time() >= $deadline) {
                // Out of budget — keep any prior cache entry so we retry it next
                // time, and stop scanning fresh sequences this round.
                if (isset($cache[$name])) $next[$name] = $cache[$name];
                continue;
            }

            $cmd = ($hasTimeout ? 'timeout 90 ' : '') . escapeshellarg($py) . ' '
                 . escapeshellarg($script) . ' --fseq ' . escapeshellarg($path)
                 . ' 2>/dev/null';
            $json = @shell_exec($cmd);
            $stats = $json ? json_decode(trim($json), true) : null;
            if (!is_array($stats)) $stats = [];
            $next[$name] = ['sig' => $sig, 'stats' => $stats];
            if ($stats) $out[$name] = $stats;
        }
        @file_put_contents($cacheFile, json_encode($next));
        return $out;
    }
}
