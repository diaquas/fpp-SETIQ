<?php
/**
 * REQ:IQ listener — runs ON the FPP host as a background loop, started
 * by scripts/postStart.sh when REQ:IQ is enabled on the plugin page.
 *
 * It replaces the desktop "bridge" service for FPP shows. Every few
 * seconds it:
 *   1. reads local playback status   (GET  127.0.0.1/api/system/status)
 *   2. reports it to the REQ:IQ cloud (POST /api/reqiq/fpp/heartbeat)
 *      — the cloud mirrors it onto the viewer page (now playing,
 *      live/offline) and decides what should play next
 *   3. executes the cloud's answer: inserts the requested sequence via
 *      FPP's "Insert Playlist After Current" / "Insert Playlist
 *      Immediate" commands, sourcing items from the "REQIQ Requests"
 *      playlist it maintains from the cloud catalog
 *
 * Auth is the same show key SET:IQ pull uses (config/fpp-SETIQ.key).
 * State for the plugin UI is written to config/fpp-SETIQ.reqiq-status.json.
 */

$pluginName = 'fpp-SETIQ';
$mediaDir   = '/home/fpp/media';
$cfgDir     = "$mediaDir/config";
$keyFile    = "$cfgDir/$pluginName.key";
$flagFile   = "$cfgDir/$pluginName.reqiq";              // enabled=1
$statusFile = "$cfgDir/$pluginName.reqiq-status.json";
$pidFile    = "/tmp/$pluginName-reqiq.pid";

$CLOUD              = 'https://lightsofelmridge.com';
$FPP                = 'http://127.0.0.1';
$INTERVAL           = 5;            // seconds between heartbeats (cloud may override)
$PLAYLIST_REFRESH   = 6 * 3600;     // re-pull the requests playlist this often
$SEQ_SYNC_INTERVAL  = 3600;         // re-report the on-box .fseq list this often

// ── Helpers ───────────────────────────────────────────────────────────

function rq_log($msg) {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

function rq_get_json($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : json_decode($body, true)];
}

function rq_post_json($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body === false ? null : json_decode($body, true)];
}

/** Same tolerant key REQ:IQ/SET:IQ use: drop .fseq, lowercase, alnum only. */
function rq_norm($name) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\.fseq$/i', '', $name)));
}

function rq_enabled($flagFile) {
    if (!file_exists($flagFile)) return false;
    return (bool) preg_match('/^enabled=1/m', file_get_contents($flagFile));
}

function rq_write_status($statusFile, $fields) {
    $fields['updatedAt'] = date('c');
    $fields['pid'] = getmypid();
    @file_put_contents($statusFile, json_encode($fields, JSON_PRETTY_PRINT));
}

/** Pull the requests playlist from the cloud and (re)create it on FPP. */
function rq_refresh_requests_playlist($CLOUD, $FPP, $key) {
    list($code, $data) = rq_get_json("$CLOUD/api/reqiq/fpp/playlist?key=" . rawurlencode($key));
    if ($code !== 200 || !is_array($data) || empty($data['playlist'])) {
        $why = is_array($data) && isset($data['error']) ? $data['error'] : "HTTP $code";
        rq_log("Requests playlist refresh failed: $why");
        return null;
    }
    $name = $data['name'];
    $ch = curl_init("$FPP/api/playlist/" . rawurlencode($name));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data['playlist']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $rc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($rc !== 200) {
        rq_log("FPP rejected requests playlist (HTTP $rc)");
        return null;
    }
    rq_log("Requests playlist \"$name\" refreshed (" . ($data['count'] ?? '?') . " songs)");
    return $name;
}

/** 1-based item index of a sequence inside an FPP playlist, or null. */
function rq_find_index($FPP, $playlistName, $sequence) {
    list($code, $data) = rq_get_json("$FPP/api/playlist/" . rawurlencode($playlistName));
    if ($code !== 200 || !is_array($data) || !isset($data['mainPlaylist'])) return null;
    $want = rq_norm($sequence);
    foreach ($data['mainPlaylist'] as $i => $entry) {
        $seq = $entry['sequenceName'] ?? '';
        if ($seq !== '' && rq_norm($seq) === $want) return $i + 1;
    }
    return null;
}

/** Report the on-box .fseq list (keeps SET:IQ reconcile + matching fresh). */
/** Seconds for one sequence from FPP's meta endpoint, or null. */
function rq_sequence_duration($FPP, $name) {
    list($code, $meta) = rq_get_json(
        "$FPP/api/sequence/" . rawurlencode($name) . "/meta"
    );
    if ($code !== 200 || !is_array($meta)) return null;
    $frames = isset($meta['NumFrames']) ? (int) $meta['NumFrames'] : 0;
    $step   = isset($meta['StepTime'])  ? (int) $meta['StepTime']  : 0; // ms/frame
    if ($frames <= 0 || $step <= 0) return null;
    return (int) round($frames * $step / 1000);
}

function rq_sync_sequences($CLOUD, $FPP, $key) {
    list($code, $data) = rq_get_json("$FPP/api/files/Sequences");
    if ($code !== 200 || !is_array($data)) return;
    $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : $data;
    $names = [];
    foreach ($files as $f) {
        $name = is_array($f) ? ($f['name'] ?? '') : (is_string($f) ? $f : '');
        if ($name !== '' && preg_match('/\.fseq$/i', $name)) $names[] = $name;
    }
    // Durations let the cloud seed catalog rows with real lengths
    // (REQ:IQ-standalone mode). All-local reads, so the loop is cheap;
    // cached across syncs since sequence lengths don't change.
    static $durCache = [];
    $durations = [];
    foreach ($names as $name) {
        if (!array_key_exists($name, $durCache)) {
            $durCache[$name] = rq_sequence_duration($FPP, $name);
        }
        if ($durCache[$name] !== null) $durations[$name] = $durCache[$name];
    }
    rq_post_json("$CLOUD/api/setiq/fpp/sync", [
        'key'       => $key,
        'sequences' => $names,
        'durations' => (object) $durations,
    ]);
}

/**
 * Tonight's rotation after the current sequence, for the viewer page's
 * "Up Next" feed. Reads the ACTIVE show playlist (not the requests
 * playlist) and rotates it to start after the current item. Cached per
 * playlist+sequence so steady-state cost is one local read per song.
 */
$rqUpcomingKey   = '';
$rqUpcomingCache = [];
function rq_build_upcoming($FPP, $playlistName, $currentSeq) {
    global $rqUpcomingKey, $rqUpcomingCache;
    $cacheKey = $playlistName . '::' . $currentSeq;
    if ($cacheKey === $rqUpcomingKey) return $rqUpcomingCache;

    list($code, $data) = rq_get_json("$FPP/api/playlist/" . rawurlencode($playlistName));
    if ($code !== 200 || !is_array($data) || !isset($data['mainPlaylist'])) return [];
    $entries = $data['mainPlaylist'];
    $n = count($entries);
    if ($n === 0) return [];

    $cur = -1;
    $want = rq_norm($currentSeq);
    foreach ($entries as $i => $e) {
        $s = $e['sequenceName'] ?? '';
        if ($s !== '' && rq_norm($s) === $want) { $cur = $i; break; }
    }

    $out = [];
    for ($k = 1; $k < $n; $k++) {
        $e = $entries[(($cur < 0 ? -1 : $cur) + $k) % $n];
        $s = $e['sequenceName'] ?? ($e['mediaName'] ?? '');
        if ($s === '') continue;
        $out[] = [
            'sequence'   => $s,
            'duration_s' => isset($e['duration']) ? (int) round((float) $e['duration']) : 0,
        ];
    }

    $rqUpcomingKey   = $cacheKey;
    $rqUpcomingCache = $out;
    return $out;
}

// ── Singleton guard ───────────────────────────────────────────────────

if (file_exists($pidFile)) {
    $old = (int) trim(file_get_contents($pidFile));
    if ($old > 0 && file_exists("/proc/$old")) {
        rq_log("Listener already running (pid $old) — exiting");
        exit(0);
    }
}
file_put_contents($pidFile, getmypid());
register_shutdown_function(function () use ($pidFile) { @unlink($pidFile); });

rq_log('REQ:IQ listener started (pid ' . getmypid() . ')');

// ── Main loop ─────────────────────────────────────────────────────────

$playlistName     = null;   // cloud tells us; cached after first heartbeat
$lastPlaylistPull = 0;
$lastSeqSync      = 0;
$lastInsertedId   = '';     // re-insert guard if /mark fails

while (true) {
    if (!rq_enabled($flagFile)) {
        rq_log('REQ:IQ disabled on the plugin page — exiting');
        break;
    }

    $key = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : '';
    if ($key === '') {
        rq_write_status($statusFile, ['ok' => false, 'error' => 'No show key — set it on the SET:IQ Pull page']);
        sleep(30);
        continue;
    }

    // Periodically re-report the sequence list (cloud matches against it).
    if (time() - $lastSeqSync > $SEQ_SYNC_INTERVAL) {
        rq_sync_sequences($CLOUD, $FPP, $key);
        $lastSeqSync = time();
    }

    // 1. Local playback status.
    list($fppCode, $fpp) = rq_get_json("$FPP/api/system/status");
    if ($fppCode !== 200 || !is_array($fpp)) {
        rq_write_status($statusFile, ['ok' => false, 'error' => "FPP status unreachable (HTTP $fppCode)"]);
        sleep($INTERVAL);
        continue;
    }

    // 2. Heartbeat to the cloud.
    $currentPlaylist = $fpp['current_playlist'] ?? null;
    $playingName     = is_array($currentPlaylist) ? ($currentPlaylist['playlist'] ?? '') : '';
    $currentSeq      = $fpp['current_sequence'] ?? '';
    $isPlaying       = strtolower($fpp['status_name'] ?? '') === 'playing';
    $upcoming        = ($isPlaying && $playingName !== '')
        ? rq_build_upcoming($FPP, $playingName, $currentSeq)
        : [];
    list($code, $resp) = rq_post_json("$CLOUD/api/reqiq/fpp/heartbeat", [
        'key' => $key,
        'fpp' => [
            'status_name'       => $fpp['status_name'] ?? '',
            'current_sequence'  => $currentSeq,
            'current_song'      => $fpp['current_song'] ?? '',
            'seconds_played'    => $fpp['seconds_played'] ?? 0,
            'seconds_remaining' => $fpp['seconds_remaining'] ?? 0,
            'playlist'          => $playingName,
            'upcoming'          => $upcoming,
        ],
    ]);

    if ($code !== 200 || !is_array($resp)) {
        $why = is_array($resp) && isset($resp['error']) ? $resp['error'] : "HTTP $code";
        rq_write_status($statusFile, ['ok' => false, 'error' => "Cloud heartbeat failed: $why"]);
        sleep($INTERVAL);
        continue;
    }

    if (!empty($resp['playlistName'])) $playlistName = $resp['playlistName'];
    if (!empty($resp['intervalSeconds'])) $INTERVAL = max(2, (int) $resp['intervalSeconds']);
    if (!empty($resp['warning'])) rq_log('Cloud: ' . $resp['warning']);

    // Keep the requests playlist fresh.
    if ($playlistName && time() - $lastPlaylistPull > $PLAYLIST_REFRESH) {
        rq_refresh_requests_playlist($CLOUD, $FPP, $key);
        $lastPlaylistPull = time();
    }

    // 3. Execute the play directive, if any.
    $play = $resp['play'] ?? null;
    if (is_array($play) && !empty($play['sequence']) && $playlistName) {
        $requestId = $play['requestId'] ?? '';
        if ($requestId !== '' && $requestId === $lastInsertedId) {
            // Already inserted; waiting for the cloud to register /mark.
            rq_log("Skipping duplicate directive for request $requestId");
        } else {
            $idx = rq_find_index($FPP, $playlistName, $play['sequence']);
            if ($idx === null) {
                // Playlist may be stale — rebuild once and retry.
                rq_refresh_requests_playlist($CLOUD, $FPP, $key);
                $lastPlaylistPull = time();
                $idx = rq_find_index($FPP, $playlistName, $play['sequence']);
            }
            if ($idx === null) {
                rq_log("\"{$play['sequence']}\" not in \"$playlistName\" — cannot insert");
            } else {
                $cmd = ($play['insert'] ?? 'after') === 'immediate'
                     ? 'Insert Playlist Immediate'
                     : 'Insert Playlist After Current';
                $url = "$FPP/api/command/" . rawurlencode($cmd) . '/'
                     . rawurlencode($playlistName) . "/$idx/$idx";
                list($rc) = rq_get_json($url);
                if ($rc === 200) {
                    rq_log("$cmd: \"{$play['sequence']}\" (item $idx)");
                    if ($requestId !== '') {
                        $lastInsertedId = $requestId;
                        rq_post_json("$CLOUD/api/reqiq/fpp/mark", ['key' => $key, 'requestId' => $requestId]);
                    }
                } else {
                    rq_log("FPP rejected $cmd (HTTP $rc)");
                }
            }
        }
    }

    rq_write_status($statusFile, [
        'ok'         => true,
        'playing'    => $fpp['current_sequence'] ?? '',
        'statusName' => $fpp['status_name'] ?? '',
        'lastPlay'   => is_array($play) ? ($play['sequence'] ?? '') : '',
    ]);

    sleep($INTERVAL);
}
