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
$lockFile   = "/tmp/$pluginName-reqiq.lock";

$CLOUD              = 'https://lightsofelmridge.com';
$FPP                = 'http://127.0.0.1';
$INTERVAL           = 5;            // seconds between heartbeats (cloud may override)
$PLAYLIST_REFRESH   = 6 * 3600;     // re-pull the requests playlist this often
$SEQ_SYNC_INTERVAL  = 3600;         // re-report the on-box .fseq list this often

// Plugin version (reported in each heartbeat for the cloud health panel).
// Sourced from pluginInfo.json so there's one place to bump it.
$PLUGIN_VERSION = 'unknown';
$infoFile = __DIR__ . '/pluginInfo.json';
if (is_readable($infoFile)) {
    $info = json_decode((string) file_get_contents($infoFile), true);
    if (is_array($info) && !empty($info['version'])) {
        $PLUGIN_VERSION = (string) $info['version'];
    }
}

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

/**
 * Run a live transport directive from the cloud (REQ:IQ Live console)
 * against the local FPP — the same actions FPP itself exposes during a
 * show. Each maps to an FPP command via GET /api/command/<Name>[/<arg>].
 *
 *   next / prev / restart → step the active playlist
 *   pause / resume        → Toggle Pause (FPP has a single toggle)
 *   stop                  → Stop Now
 *   volume                → Volume Set <0-100>
 *   jump                  → Start Playlist At Item <currentPlaylist> <index>
 *   start                 → Start Playlist At Item <arg playlist> <index>
 *                           (starts a NOT-yet-running playlist — the operator's
 *                           "Start show now" from the REQ:IQ Showtime card; the
 *                           cloud sends tonight's playlist + item 1 = opener)
 *
 * `$currentPlaylist` is the name of the playlist FPP is running now — needed
 * by "jump" to resolve the target item by name within it.
 */
function rq_exec_command($FPP, $command, $currentPlaylist = '') {
    $type = is_array($command) ? ($command['type'] ?? '') : '';
    if ($type === '') return;

    $map = [
        'next'    => ['Next Playlist Item'],
        'prev'    => ['Prev Playlist Item'],
        'restart' => ['Restart Playlist Item'],
        'stop'    => ['Stop Now'],
        'pause'   => ['Toggle Pause'],
        'resume'  => ['Toggle Pause'],
    ];

    if ($type === 'volume') {
        $vol = (int) round((float) ($command['value'] ?? 0));
        $vol = max(0, min(100, $vol));
        $parts = ['Volume Set', $vol];
    } elseif ($type === 'jump') {
        if ($currentPlaylist === '') {
            rq_log("Jump ignored — no playlist is running");
            return;
        }
        // Prefer matching the target step by name in the running playlist
        // (robust to rotation drift); fall back to the reported 1-based index.
        $arg = isset($command['arg']) ? (string) $command['arg'] : '';
        $idx = $arg !== '' ? rq_find_index($FPP, $currentPlaylist, $arg) : null;
        if ($idx === null) {
            $idx = (int) round((float) ($command['value'] ?? 0));
        }
        if ($idx < 1) {
            rq_log("Jump target \"$arg\" not found in \"$currentPlaylist\" — ignored");
            return;
        }
        $parts = ['Start Playlist At Item', $currentPlaylist, $idx];
    } elseif ($type === 'start') {
        // Start a specific playlist (need not be running) at an item — the
        // operator's "Start show now". The cloud sends tonight's SET:IQ
        // playlist in `arg` and item 1 (the opener, past the pre-show).
        $playlist = isset($command['arg']) ? (string) $command['arg'] : '';
        if ($playlist === '') {
            rq_log("Start ignored — no playlist named");
            return;
        }
        $idx = (int) round((float) ($command['value'] ?? 1));
        if ($idx < 1) $idx = 1;
        $parts = ['Start Playlist At Item', $playlist, $idx];
    } elseif (isset($map[$type])) {
        $parts = $map[$type];
    } else {
        rq_log("Unknown transport command \"$type\" — ignored");
        return;
    }

    $url = "$FPP/api/command/" . implode('/', array_map('rawurlencode', $parts));
    list($rc) = rq_get_json($url);
    if ($rc === 200) {
        rq_log("Transport: " . implode(' ', $parts));
    } else {
        rq_log("FPP rejected transport \"" . implode(' ', $parts) . "\" (HTTP $rc)");
    }
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
/** Duration (seconds) + media filename for one sequence, from FPP's
 *  meta endpoint. xLights writes the audio path into the fseq header
 *  (variableHeaders.mf), which is how we chain through to ID3 tags. */
function rq_sequence_info($FPP, $name) {
    list($code, $meta) = rq_get_json(
        "$FPP/api/sequence/" . rawurlencode($name) . "/meta"
    );
    if ($code !== 200 || !is_array($meta)) return ['duration' => null, 'media' => null];
    $frames = isset($meta['NumFrames']) ? (int) $meta['NumFrames'] : 0;
    $step   = isset($meta['StepTime'])  ? (int) $meta['StepTime']  : 0; // ms/frame
    $duration = ($frames > 0 && $step > 0) ? (int) round($frames * $step / 1000) : null;

    $mf = $meta['variableHeaders']['mf'] ?? '';
    $media = null;
    if (is_string($mf) && $mf !== '') {
        $base = basename(str_replace('\\', '/', $mf));
        if ($base !== '') $media = $base;
    }
    return ['duration' => $duration, 'media' => $media];
}

/** ID3-ish tags (title/artist/album) for a media file via FPP. */
function rq_media_id3($FPP, $mediaName) {
    list($code, $meta) = rq_get_json(
        "$FPP/api/media/" . rawurlencode($mediaName) . "/meta"
    );
    if ($code !== 200 || !is_array($meta)) return null;
    $tags = $meta['format']['tags'] ?? null;
    if (!is_array($tags)) return null;
    $get = function ($want) use ($tags) {
        foreach ($tags as $k => $v) {
            if (strtolower($k) === $want && is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    };
    $out = [];
    foreach (['title', 'artist', 'album'] as $f) {
        $v = $get($f);
        if ($v !== null) $out[$f] = $v;
    }
    return $out !== [] ? $out : null;
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
    // Durations + ID3 tags let the cloud seed catalog rows with real
    // lengths, titles and artists (REQ:IQ-standalone mode). All-local
    // reads, cached across syncs — fseq headers and ID3 don't change.
    // This stays a cheap, near-instant report: it never parses sequence
    // frames — the catalog's programming stats come from the .xsq drop in
    // Studio IQ, not the box.
    static $infoCache = [];
    static $id3Cache  = [];
    $durations = [];
    $id3       = [];
    foreach ($names as $name) {
        if (!array_key_exists($name, $infoCache)) {
            $infoCache[$name] = rq_sequence_info($FPP, $name);
        }
        $info = $infoCache[$name];
        if ($info['duration'] !== null) $durations[$name] = $info['duration'];
        if ($info['media'] !== null) {
            if (!array_key_exists($info['media'], $id3Cache)) {
                $id3Cache[$info['media']] = rq_media_id3($FPP, $info['media']);
            }
            if ($id3Cache[$info['media']] !== null) {
                $id3[$name] = $id3Cache[$info['media']];
            }
        }
    }

    rq_post_json("$CLOUD/api/setiq/fpp/sync", [
        'key'       => $key,
        'sequences' => $names,
        'durations' => (object) $durations,
        'id3'       => (object) $id3,
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

// ── Singleton guard (atomic) ──────────────────────────────────────────
//
// An exclusive, non-blocking file lock held for the whole run. The old
// check-then-write on the pid file had a race window: two starts firing close
// together (enable, install, watchdog cron, key-save, page self-heal — several
// of which can land at once during setup) could BOTH pass the "is it running?"
// check and then both run. Duplicate listeners are corrosive — they each
// report their own seconds_played (so the viewer's now-playing clock jumps),
// both act on the same request directive (double-inserted songs, stray jumps)
// and both drain+execute the one-shot transport command. flock is atomic at
// the OS level, so a second instance can never slip through, however many
// start paths race. The lock file is intentionally never unlinked (the lock is
// by open fd, not by path); only the pid file — which the plugin UI reads — is
// cleaned up on exit.
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    rq_log('Listener already running (lock held) — exiting');
    exit(0);
}
file_put_contents($pidFile, getmypid());
register_shutdown_function(function () use ($pidFile) { @unlink($pidFile); });

rq_log('REQ:IQ listener started (pid ' . getmypid() . ')');

// ── Main loop ─────────────────────────────────────────────────────────

$playlistName     = null;   // cloud tells us; cached after first heartbeat
$lastPlaylistPull = 0;
$lastSeqSync      = 0;
$lastInsertedId   = '';     // re-insert guard if /mark fails
$lastBeat         = 0;      // when we last POSTed a heartbeat
$lastSig          = null;   // last reported now-playing signature

// Read local FPP status this often. The localhost status call is cheap, so we
// poll it fast and only POST to the cloud when the now-playing signature
// CHANGES or the keepalive ($INTERVAL) is due. That puts the viewer's
// now-playing within ~1s of FPP reporting a change — instead of waiting out a
// full heartbeat — without a high steady-state cloud POST rate.
$POLL = 1;

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

    // 1. Local playback status (cheap localhost call, every tick).
    list($fppCode, $fpp) = rq_get_json("$FPP/api/system/status");
    if ($fppCode !== 200 || !is_array($fpp)) {
        rq_write_status($statusFile, ['ok' => false, 'error' => "FPP status unreachable (HTTP $fppCode)"]);
        // FPP's local API hiccups (restart, heavy load) shouldn't make the box
        // look OFFLINE in the REQ:IQ console — the listener (and the box) are
        // clearly still up. Keep a heartbeat flowing on the keepalive cadence,
        // marked unreachable, so `last_heartbeat_at` stays fresh and the cloud
        // reads "online, FPP API quiet" instead of going dark.
        if ((time() - $lastBeat) >= $INTERVAL) {
            $lastBeat = time();
            $lastSig  = null; // force a real beat once FPP comes back
            rq_post_json("$CLOUD/api/reqiq/fpp/heartbeat", [
                'key' => $key,
                'fpp' => [
                    'status_name'    => 'unreachable',
                    'plugin_version' => $PLUGIN_VERSION,
                ],
            ]);
        }
        sleep($POLL);
        continue;
    }

    $currentPlaylist = $fpp['current_playlist'] ?? null;
    $playingName     = is_array($currentPlaylist) ? ($currentPlaylist['playlist'] ?? '') : '';
    $currentSeq      = $fpp['current_sequence'] ?? '';
    $isPlaying       = strtolower($fpp['status_name'] ?? '') === 'playing';
    // FPP master volume (0–100). Reported back to the cloud so the REQ:IQ
    // console slider mirrors volume changes made on the box itself.
    $volume          = isset($fpp['volume']) ? (int) $fpp['volume'] : null;

    // Periodically re-report the (cheap) sequence list + durations + ID3.
    if (time() - $lastSeqSync > $SEQ_SYNC_INTERVAL) {
        rq_sync_sequences($CLOUD, $FPP, $key);
        $lastSeqSync = time();
    }

    // Only beat when what the viewer sees changes, or the keepalive is due.
    // The keepalive also bounds how fast a queued transport directive is
    // picked up, so it stays short. Volume is in the signature so an FPP-side
    // volume change pushes on the next tick rather than waiting for keepalive.
    $sig = ($isPlaying ? 'play' : 'stop') . '|' . $currentSeq . '|' . $playingName
         . '|' . ($volume === null ? '' : $volume);
    if ($sig === $lastSig && (time() - $lastBeat) < $INTERVAL) {
        sleep($POLL);
        continue;
    }
    $lastSig  = $sig;
    $lastBeat = time();

    // 2. Heartbeat to the cloud.
    $upcoming = ($isPlaying && $playingName !== '')
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
            'volume'            => $volume,
            'plugin_version'    => $PLUGIN_VERSION,
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

    // 4. Execute a live transport directive (next/pause/volume/jump/…), if any.
    $command = $resp['command'] ?? null;
    if (is_array($command) && !empty($command['type'])) {
        rq_exec_command($FPP, $command, $playingName);
    }

    rq_write_status($statusFile, [
        'ok'          => true,
        'playing'     => $fpp['current_sequence'] ?? '',
        'statusName'  => $fpp['status_name'] ?? '',
        'lastPlay'    => is_array($play) ? ($play['sequence'] ?? '') : '',
        'lastCommand' => is_array($command) ? ($command['type'] ?? '') : '',
    ]);

    sleep($POLL);
}
