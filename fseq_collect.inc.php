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
    /** On-disk path to a sequence's .fseq, or null. */
    function setiq_fseq_path($mediaDir, $name) {
        foreach (['sequences', 'Sequences'] as $sub) {
            $p = "$mediaDir/$sub/$name";
            if (is_file($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('setiq_run_parallel')) {
    /**
     * Run shell commands concurrently, bounded to $maxParallel at a time, and
     * return name => stdout for each that finished before $deadline. The .fseq
     * parse is CPU-bound, so fanning out across the box's cores (instead of one
     * file at a time) is the main speedup. Commands not started before the
     * deadline are simply absent from the result (the caller preserves their
     * prior cache and retries them next round).
     */
    function setiq_run_parallel(array $cmds, $maxParallel, $deadline) {
        $out = [];
        $queue = array_keys($cmds);
        $running = []; // name => ['proc'=>res, 'pipe'=>res, 'buf'=>string]
        $desc = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];

        while ($queue || $running) {
            // Fill the pool up to the limit while there's still time budget.
            while ($queue && count($running) < $maxParallel && time() < $deadline) {
                $name = array_shift($queue);
                $pipes = [];
                $proc = @proc_open($cmds[$name], $desc, $pipes);
                if (!is_resource($proc)) continue; // couldn't start — skip
                stream_set_blocking($pipes[1], false);
                $running[$name] = ['proc' => $proc, 'pipe' => $pipes[1], 'buf' => ''];
            }
            if (!$running) break; // nothing in flight (queue empty or out of budget)

            foreach ($running as $name => &$r) {
                $chunk = fread($r['pipe'], 65536);
                if (is_string($chunk) && $chunk !== '') $r['buf'] .= $chunk;
                $st = proc_get_status($r['proc']);
                if (!$st['running']) {
                    $rem = stream_get_contents($r['pipe']);
                    if (is_string($rem)) $r['buf'] .= $rem;
                    fclose($r['pipe']);
                    proc_close($r['proc']);
                    $out[$name] = $r['buf'];
                    unset($running[$name]);
                }
            }
            unset($r);

            if (!$queue && !$running) break;
            usleep(15000); // 15ms — don't busy-spin while children work
        }
        return $out;
    }
}

if (!function_exists('setiq_core_count')) {
    /** Cores available for the parallel parse (1 on a single-core box). */
    function setiq_core_count() {
        $n = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        return $n > 0 ? $n : 1;
    }
}

if (!function_exists('setiq_collect_stats')) {
    /**
     * Per-sequence stats (lighting cues, top-3 colors, and run-length lit
     * channel ranges) computed on the box by scripts/fseq_stats.py from the
     * rendered .fseq. The box has no xLights layout (it lives in Studio IQ),
     * so prop attribution is NOT done here — the cloud reconciles the
     * `activity` runs against the uploaded layout. Cached by file signature
     * so unchanged sequences aren't re-scanned; fresh scans fan out across
     * cores and are bounded by $budgetSec so a first run never hangs the
     * caller — anything not reached is picked up (and cached) next call.
     *
     * $budgetSec bounds time spent on FRESH scans this call (cache hits are
     * always returned, instantly). The interactive Pull page passes the full
     * budget; the listener passes a small one (or 0 while a show is playing)
     * so a first-time scan never stalls live transport.
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

        // First pass: serve cache hits; queue the rest (sig kept for the cache).
        $todo = []; // name => ['sig'=>..., 'cmd'=>...]
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

            $todo[$name] = [
                'sig' => $sig,
                'cmd' => ($hasTimeout ? 'timeout 90 ' : '') . escapeshellarg($py) . ' '
                       . escapeshellarg($script) . ' --fseq ' . escapeshellarg($path)
                       . ' 2>/dev/null',
            ];
        }

        // Fan the fresh scans out across cores. Capped at 4 so a big show can't
        // oversubscribe a small box's RAM (each worker mmaps its own .fseq).
        $cmds = [];
        foreach ($todo as $name => $t) $cmds[$name] = $t['cmd'];
        $results = $cmds
            ? setiq_run_parallel($cmds, max(1, min(setiq_core_count(), 4)), $deadline)
            : [];

        foreach ($todo as $name => $t) {
            if (array_key_exists($name, $results)) {
                $json = trim($results[$name]);
                $stats = $json !== '' ? json_decode($json, true) : null;
                if (!is_array($stats)) $stats = [];
                $next[$name] = ['sig' => $t['sig'], 'stats' => $stats];
                if ($stats) $out[$name] = $stats;
            } elseif (isset($cache[$name])) {
                // Didn't run this round (out of budget) — keep the old cache
                // entry so it's retried next time.
                $next[$name] = $cache[$name];
            }
        }

        @file_put_contents($cacheFile, json_encode($next));
        return $out;
    }
}
