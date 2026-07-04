<?php
/**
 * SET:IQ Playlist Importer — "Pull from SET:IQ" page.
 * Runs ON the FPP host. Given the show's SET:IQ key, fetches the generated
 * playlists from the SET:IQ cloud and creates them locally — no manual
 * upload. Manual, on-demand: nothing happens unless you click Pull.
 */

$pluginName = 'fpp-SETIQ';
$cfgDir  = (isset($settings['configDirectory']) && $settings['configDirectory'])
           ? $settings['configDirectory'] : '/home/fpp/media/config';
$keyFile = "$cfgDir/$pluginName.key";
$pullStatusFile = "$cfgDir/$pluginName.pull-status.json";
// The operator's public REQ:IQ viewer link, refreshed from the cloud on
// each pull. The REQ:IQ tab opens this so it points at <slug>.reqiq.net.
$reqiqUrlFile = "$cfgDir/$pluginName.reqiq-url";
// The last key the cloud actually accepted — drives the live "valid" badge,
// which must reflect a verified key, not merely "a key is saved".
$verifiedKeyFile = "$cfgDir/$pluginName.key-verified";

$SETIQ_BASE = 'https://lightsofelmridge.com';

// FPP command that fires a saved Command Preset by name. SET:IQ schedules its
// triggers through named "<Show> - …" presets so they're identifiable; this is
// the one place to change if FPP ever renames the command. Guarded so pull.php
// and manage.php can both define it without colliding.
if (!defined('SETIQ_RUN_PRESET_CMD')) define('SETIQ_RUN_PRESET_CMD', 'Run Command Preset');

/** Render the "Last pull" timestamp as "today · 4:12 PM" / "Jun 3 · …". */
function setiq_pull_when($ts) {
    $ts = (int) $ts;
    if ($ts <= 0) return '—';
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d'))                 $day = 'today';
    elseif ($d === date('Y-m-d', time() - 86400)) $day = 'yesterday';
    else                                      $day = date('M j', $ts);
    return $day . ' · ' . date('g:i A', $ts);
}

function setiq_get_json($url, $extraHeaders = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $extraHeaders),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function setiq_post_playlist($name, $body) {
    $ch = curl_init('http://127.0.0.1/api/playlist/' . rawurlencode($name));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** The .fseq files actually on this box (FPP file API). */
function setiq_local_sequences() {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/files/Sequences');
    if ($code !== 200) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : $data;
    $names = [];
    foreach ($files as $f) {
        $name = is_array($f) ? ($f['name'] ?? '') : (is_string($f) ? $f : '');
        if ($name !== '' && preg_match('/\.fseq$/i', $name)) $names[] = $name;
    }
    return $names;
}

/** The commands this box can run (FPP /api/commands) — name + argument
 *  schema, including any plugin commands (Remote Falcon, etc.). Returns a
 *  trimmed [{name, args}] list, or [] when unavailable. */
function setiq_local_commands() {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/commands');
    if ($code !== 200) return [];
    $data = json_decode($body, true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $c) {
        if (!is_array($c)) continue;
        $name = $c['name'] ?? '';
        if (!is_string($name) || $name === '') continue;
        $args = (isset($c['args']) && is_array($c['args'])) ? $c['args'] : [];
        $out[] = ['name' => $name, 'args' => $args];
    }
    return $out;
}

/** Duration (seconds) + media filename for one sequence. xLights
 *  writes the audio path into the fseq header (variableHeaders.mf). */
function setiq_sequence_info($name) {
    list($code, $body) = setiq_get_json(
        'http://127.0.0.1/api/sequence/' . rawurlencode($name) . '/meta'
    );
    if ($code !== 200) return ['duration' => null, 'media' => null];
    $meta = json_decode($body, true);
    if (!is_array($meta)) return ['duration' => null, 'media' => null];
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
function setiq_media_id3($mediaName) {
    list($code, $body) = setiq_get_json(
        'http://127.0.0.1/api/media/' . rawurlencode($mediaName) . '/meta'
    );
    if ($code !== 200) return null;
    $meta = json_decode($body, true);
    $tags = is_array($meta) ? ($meta['format']['tags'] ?? null) : null;
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

/**
 * Report the on-box sequence list to SET:IQ so its calendar can lock
 * songs that aren't here yet ("Sync with FPP" reconcile). Durations
 * and ID3 tags ride along so the cloud can seed REQ:IQ catalog rows
 * with real lengths, titles and artists (standalone mode).
 */
function setiq_sync_sequences($base, $key) {
    $names = setiq_local_sequences();
    if ($names === null) return [false, 'could not read the local sequence list', 0];
    $durations = [];
    $id3       = [];
    foreach ($names as $name) {
        $info = setiq_sequence_info($name);
        if ($info['duration'] !== null) $durations[$name] = $info['duration'];
        if ($info['media'] !== null) {
            $tags = setiq_media_id3($info['media']);
            if ($tags !== null) $id3[$name] = $tags;
        }
    }
    // First load is deliberately near-instant: filenames + runtimes (fseq
    // header) + ID3 tags only — all cheap reads. The heavy per-.fseq frame
    // scan no longer runs here; the fseq-derived catalog stats (props, fave
    // prop, palette, key moments) are computed in the cloud/browser from each
    // render the box stages later (see the REQ:IQ listener's staging pass).
    // The box's live command list (FPP version + installed plugins decide
    // it) so SET:IQ's show-day trigger picker offers the real set, never a
    // hardcoded one. Best-effort: a missing/oddly-shaped list must not fail
    // the sync.
    $commands = setiq_local_commands();

    $payload = json_encode([
        'key'       => $key,
        'sequences' => $names,
        'durations' => (object) $durations,
        'id3'       => (object) $id3,
        'commands'  => $commands,
    ]);
    $ch = curl_init("$base/api/setiq/fpp/sync");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200
        ? [true, count($names) . ' sequence(s) reported to SET:IQ', count($names)]
        : [false, "SET:IQ rejected the sync (HTTP $code)", count($names)];
}

/**
 * Push the SET:IQ-generated schedule into FPP's scheduler.
 *
 * FPP's schedule is one shared array (schedule.json), so this is a
 * scoped sync, not a wholesale replace: every SET:IQ playlist is named
 * "<Show> - <Night>", so entries whose playlist carries that prefix are
 * ours to manage — they're swapped for the fresh set (which also drops
 * stale entries for nights removed from the plan) while every other
 * entry (background lights, etc.) is preserved untouched.
 */
/** The FPP-safe "<Show> - " prefix that tags every SET:IQ-owned name
 *  (playlists AND command presets). Empty-ish show → " - ", treated as no
 *  prefix so we never match on it. */
function setiq_owned_prefix($showName) {
    return trim(preg_replace('/[^-a-zA-Z0-9_ ]/', '', $showName)) . ' - ';
}

/**
 * True when a schedule row is one of SET:IQ's triggers: a command row that
 * fires a "<Show> - …" Command Preset via "Run Command Preset". These carry
 * an empty playlist (FPP marks command rows that way), so the playlist-prefix
 * rule can't see them — without this they'd duplicate on every pull and be
 * undeletable. Matching on the preset name in args[0] is what lets us own
 * them, while leaving the operator's own command rows untouched.
 */
function setiq_entry_is_setiq_trigger($e, $prefix) {
    if (!is_array($e) || $prefix === ' - ') return false;
    if (($e['command'] ?? '') !== SETIQ_RUN_PRESET_CMD) return false;
    $args = (isset($e['args']) && is_array($e['args'])) ? $e['args'] : [];
    $first = isset($args[0]) ? (string) $args[0] : '';
    return $first !== '' && strpos($first, $prefix) === 0;
}

function setiq_update_schedule($showName, $entries) {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/schedule');
    if ($code !== 200) return [false, "could not read the FPP schedule (HTTP $code)"];
    $existing = json_decode($body, true);
    if (!is_array($existing)) $existing = [];

    // Same character rules as SET:IQ's playlist naming (FPP-safe names).
    $prefix = setiq_owned_prefix($showName);
    $kept = [];
    $replaced = 0;
    foreach ($existing as $e) {
        $pl = is_array($e) ? ($e['playlist'] ?? '') : '';
        $ownedByPlaylist = ($prefix !== ' - ' && $pl !== '' && strpos($pl, $prefix) === 0);
        // Trigger rows (command presets) are ours too — replace, not keep,
        // so they stop piling up on every pull.
        if ($ownedByPlaylist || setiq_entry_is_setiq_trigger($e, $prefix)) {
            $replaced++;
            continue;
        }
        $kept[] = $e;
    }
    $merged = array_merge($kept, $entries);

    $ch = curl_init('http://127.0.0.1/api/schedule');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($merged),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $rc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($rc !== 200) return [false, "FPP rejected the schedule write (HTTP $rc)"];

    // Tell fppd to re-read it. Failure is non-fatal (fppd may be down;
    // the saved schedule loads on next start).
    $ch = curl_init('http://127.0.0.1/api/schedule/reload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);

    return [true, count($entries) . ' show entr' . (count($entries) === 1 ? 'y' : 'ies')
        . " written ($replaced replaced, " . count($kept) . ' non-SET:IQ kept)'];
}

/**
 * Ensure SET:IQ's Command Presets exist on this box before the schedule's
 * trigger rows fire them. FPP keeps presets in config/commandPresets.json;
 * this reads it, drops any prior SET:IQ presets (name prefixed "<Show> - "),
 * splices in the fresh set and writes it back — the same scoped-merge model
 * as the schedule, so the operator's own presets are never touched. A trigger
 * with no backing preset would fire nothing, which is exactly the breakage
 * the named-preset approach exists to prevent.
 */
function setiq_apply_command_presets($showName, $presets) {
    $prefix = setiq_owned_prefix($showName);
    if ($prefix === ' - ') return [false, 0];

    list($code, $body) = setiq_get_json('http://127.0.0.1/api/configfile/commandPresets.json');
    $cfg = ($code === 200) ? json_decode($body, true) : null;
    // FPP stores the file as { "commandPresets": [ … ] }; tolerate a bare array.
    $existing = [];
    if (is_array($cfg)) {
        $existing = (isset($cfg['commandPresets']) && is_array($cfg['commandPresets']))
                  ? $cfg['commandPresets'] : $cfg;
    }
    // Keep every preset that isn't a prior SET:IQ one.
    $kept = [];
    foreach ($existing as $p) {
        $name = is_array($p) ? (string) ($p['name'] ?? '') : '';
        if ($name !== '' && strpos($name, $prefix) === 0) continue;
        $kept[] = $p;
    }
    // Normalize the cloud's preset defs into FPP's preset shape.
    $fresh = [];
    foreach ($presets as $p) {
        if (!is_array($p)) continue;
        $name = isset($p['name']) ? (string) $p['name'] : '';
        $cmd  = isset($p['command']) ? (string) $p['command'] : '';
        if ($name === '' || $cmd === '') continue;
        $args = (isset($p['args']) && is_array($p['args'])) ? array_values($p['args']) : [];
        $fresh[] = [
            'name'             => $name,
            'command'          => $cmd,
            'args'             => $args,
            'multisyncCommand' => !empty($p['multisyncCommand']),
            'multisyncHosts'   => '',
            'description'      => 'SET:IQ trigger',
        ];
    }
    $merged = array_values(array_merge($kept, $fresh));

    $ch = curl_init('http://127.0.0.1/api/configfile/commandPresets.json');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['commandPresets' => $merged]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $rc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$rc === 200, count($fresh)];
}

/**
 * Read every playlist that already exists on this FPP box: the names
 * from `GET /api/playlists`, then each playlist's full body (with its
 * mainPlaylist running order) from `GET /api/playlist/<name>`. Returns a
 * list of raw FPP playlist objects — the cloud normalizes the shapes.
 */
function setiq_local_playlists() {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/playlists');
    if ($code !== 200) return null;
    $list = json_decode($body, true);
    if (!is_array($list)) return null;
    $out = [];
    foreach ($list as $entry) {
        // FPP returns either bare name strings or {name: …} objects.
        $name = is_array($entry) ? ($entry['name'] ?? '') : (is_string($entry) ? $entry : '');
        $name = trim((string) $name);
        if ($name === '') continue;
        list($pc, $pb) = setiq_get_json('http://127.0.0.1/api/playlist/' . rawurlencode($name));
        if ($pc !== 200) continue;
        $pl = json_decode($pb, true);
        if (!is_array($pl)) continue;
        if (!isset($pl['name'])) $pl['name'] = $name;
        // Forward name + running order + the description. SET:IQ uses the
        // description to tell its OWN playlists (desc "Generated by SET:IQ")
        // from FPP-authored ones, which drives playlist provenance — without
        // it the cloud can't lock FPP playlists against the auto-builder.
        $desc = '';
        if (isset($pl['desc']) && is_string($pl['desc'])) {
            $desc = $pl['desc'];
        } elseif (isset($pl['playlistInfo']['description']) && is_string($pl['playlistInfo']['description'])) {
            $desc = $pl['playlistInfo']['description'];
        }
        $out[] = [
            'name'         => $pl['name'],
            'desc'         => $desc,
            'mainPlaylist' => isset($pl['mainPlaylist']) && is_array($pl['mainPlaylist'])
                              ? $pl['mainPlaylist'] : [],
        ];
    }
    return $out;
}

/** Read this box's scheduler entries (`GET /api/schedule`). */
function setiq_local_schedule() {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/schedule');
    if ($code !== 200) return null;
    $sched = json_decode($body, true);
    return is_array($sched) ? $sched : null;
}

/**
 * Push the box's existing playlists + schedule UP to SET:IQ so the
 * operator can import the show they already built and tweak it in the
 * app. SET:IQ only stores a snapshot — it never overwrites their season
 * automatically (the operator reviews and applies in the editor).
 */
function setiq_push_import($base, $key) {
    $playlists = setiq_local_playlists();
    if ($playlists === null) return [false, 'could not read this box\'s playlists'];
    $schedule = setiq_local_schedule();
    if ($schedule === null) $schedule = [];
    if (count($playlists) === 0 && count($schedule) === 0) {
        return [false, 'no playlists or schedule found on this FPP'];
    }
    $payload = json_encode([
        'key'       => $key,
        'host'      => php_uname('n'),
        'playlists' => $playlists,
        'schedule'  => $schedule,
    ]);
    $ch = curl_init("$base/api/setiq/fpp/import");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404) return [false, 'SET:IQ didn\'t recognize the key'];
    if ($code !== 200) return [false, "SET:IQ rejected the import (HTTP $code)"];
    // SET:IQ echoes what it actually STORED ({playlists, schedule, items}).
    // Report that receipt rather than what we think we sent — a push whose
    // playlists all land with zero songs is the classic silent failure, and
    // this is the one place it can be caught at the moment of pushing.
    $receipt = is_string($resp) ? json_decode($resp, true) : null;
    if (is_array($receipt) && isset($receipt['playlists'])) {
        $pl    = (int) $receipt['playlists'];
        $rows  = (int) ($receipt['schedule'] ?? 0);
        $songs = array_key_exists('items', $receipt) ? (int) $receipt['items'] : null;
        $msg = "$pl playlist(s)"
             . ($songs === null ? '' : " with $songs song" . ($songs === 1 ? '' : 's'))
             . " and $rows schedule entr" . ($rows === 1 ? 'y' : 'ies')
             . ' stored by SET:IQ';
        if ($songs === 0 && $pl > 0) {
            return [false, "$msg — the playlists arrived EMPTY, so nothing can"
                . ' be placed on the SET:IQ calendar. Check that this box\'s'
                . ' playlists have entries (FPP → Content Setup → Playlists),'
                . ' then push again'];
        }
        return [true, $msg];
    }
    // Older SET:IQ deployments answer 200 without a receipt body.
    return [true, count($playlists) . ' playlist(s) and ' . count($schedule)
        . ' schedule entr' . (count($schedule) === 1 ? 'y' : 'ies')
        . ' sent to SET:IQ'];
}

/** Fetch the show's playlists (+ schedule) from the SET:IQ cloud. */
function setiq_fetch_cloud($base, $key) {
    // Self-report the hostname so SET:IQ's dialog can show
    // "last pulled by <host>".
    // presets=1 opts this box into named Command Presets for triggers — the
    // cloud then emits "Run Command Preset" schedule rows we can identify and
    // delete, instead of anonymous raw command rows.
    list($code, $body) = setiq_get_json(
        "$base/api/setiq/fpp/playlists?key=" . rawurlencode($key) . "&presets=1",
        ['X-FPP-Host: ' . php_uname('n')]
    );
    $data = json_decode($body, true);
    if ($code !== 200 || !is_array($data) || !isset($data['playlists'])) {
        return ["Couldn't reach SET:IQ or the key is invalid (HTTP $code).", null];
    }
    return [null, $data];
}

/**
 * Content signature for change detection: the ordered sequence|media
 * pairs. Durations are excluded on purpose — FPP rewrites them with the
 * real media length, which would flag every playlist as changed.
 */
function setiq_signature($playlist) {
    $sig = [];
    foreach (($playlist['mainPlaylist'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $sig[] = ($e['sequenceName'] ?? '') . '|' . ($e['mediaName'] ?? '');
    }
    return $sig;
}

/** Signature of the playlist as it exists on this box, or null if absent. */
function setiq_box_signature($name) {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/playlist/' . rawurlencode($name));
    if ($code !== 200) return null;
    $pl = json_decode($body, true);
    return is_array($pl) ? setiq_signature($pl) : null;
}

/** Compare every cloud playlist against the box: name → [items, status]. */
function setiq_compare_rows($data) {
    $rows = [];
    foreach ($data['playlists'] as $p) {
        $name = $p['name'] ?? '';
        if ($name === '') continue;
        $cloud = setiq_signature($p['playlist'] ?? []);
        $box = setiq_box_signature($name);
        if ($box === null)          $status = 'new';
        elseif ($box === $cloud)    $status = 'up to date';
        else                        $status = 'update available';
        $rows[] = [$name, count($cloud), $status];
    }
    return $rows;
}

$results = [];
$rows = [];
$showName = '';
$error = '';
$notice = '';
$syncMsg = '';
$schedMsg = '';
$schedErr = '';
$importMsg = '';
$action = $_POST['action'] ?? '';

// Disconnect: forget the show key and stop talking to SET:IQ/REQ:IQ. The
// disconnect form posts no key field, so the key-save below won't re-add it.
if ($action === 'disconnect') {
    @unlink($keyFile);
    @unlink($verifiedKeyFile);
    @unlink($reqiqUrlFile);
    @unlink($pullStatusFile);
    // Stop REQ:IQ too, so the box is fully detached: clear its enable flag
    // and kill a running listener (it also exits on its own next tick).
    @file_put_contents("$cfgDir/$pluginName.reqiq", "enabled=0\n");
    $reqiqPidFile = "/tmp/$pluginName-reqiq.pid";
    if (file_exists($reqiqPidFile)) {
        $rpid = (int) trim((string) @file_get_contents($reqiqPidFile));
        if ($rpid > 0 && file_exists("/proc/$rpid")) @exec('kill ' . $rpid);
        @unlink($reqiqPidFile);
    }
    $notice = 'Disconnected — the show key was removed and REQ:IQ stopped. '
            . 'This box no longer talks to SET:IQ or REQ:IQ until you paste a key again.';
}

// Save the key when submitted (any POST that carries the key field).
$key = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : '';
if (isset($_POST['key'])) {
    $key = trim($_POST['key']);
    if ($key === '') {
        @unlink($keyFile);
        @unlink($verifiedKeyFile);
    } else {
        @file_put_contents($keyFile, $key);
    }
}

// First time a show key is saved, bring REQ:IQ up automatically so the listener
// (now playing + viewer requests) runs without a separate enable step — the box
// "just works" once it has a key. Only when REQ:IQ was never configured (no flag
// yet): a deliberate disable (enabled=0) or a Disconnect is respected, and
// Disconnect posts no key field so it never reaches here.
if (isset($_POST['key']) && $key !== '') {
    $reqiqFlag = "$cfgDir/$pluginName.reqiq";
    if (!file_exists($reqiqFlag)) {
        @file_put_contents($reqiqFlag, "enabled=1\n");
        $reqiqPidFile = "/tmp/$pluginName-reqiq.pid";
        $running = false;
        if (file_exists($reqiqPidFile)) {
            $rp = (int) trim((string) @file_get_contents($reqiqPidFile));
            $running = ($rp > 0 && file_exists("/proc/$rp"));
        }
        if (!$running) {
            $reqiqDir = "/home/fpp/media/plugins/$pluginName";
            $reqiqLog = "/home/fpp/media/logs/$pluginName-reqiq.log";
            @exec('setsid nohup /usr/bin/php '
                . escapeshellarg("$reqiqDir/reqiq_listener.php")
                . ' < /dev/null >> ' . escapeshellarg($reqiqLog) . ' 2>&1 &');
        }
    }
}

// Per-playlist Pull buttons submit their playlist name as "pullone".
$pullOneName = isset($_POST['pullone']) && is_string($_POST['pullone'])
             ? trim($_POST['pullone']) : '';
if ($pullOneName !== '') $action = 'pullone';
if (in_array($action, ['pull', 'check', 'pullone', 'sync', 'import'], true)) {
    $data = null;
    if ($key === '') {
        $error = 'Enter your SET:IQ show key first.';
    } elseif ($action !== 'sync' && $action !== 'import') {
        list($error, $data) = setiq_fetch_cloud($SETIQ_BASE, $key);
        if ($data) $showName = $data['show'] ?? '';
        // The cloud accepted this key — remember it as verified so the "valid"
        // badge reflects a real check, not just "a key is saved".
        if ($data) @file_put_contents($verifiedKeyFile, $key);
        // Pull the operator's REQ:IQ link over with the playlists. Only
        // replace the stored one when it actually changed.
        if ($data && isset($data['reqiqUrl']) && is_string($data['reqiqUrl'])) {
            $url = trim($data['reqiqUrl']);
            if ($url !== '') {
                $cur = file_exists($reqiqUrlFile)
                     ? trim((string) file_get_contents($reqiqUrlFile)) : '';
                if ($url !== $cur) @file_put_contents($reqiqUrlFile, $url);
            }
        }
    }

    if ($action === 'pull' && $data) {
        foreach ($data['playlists'] as $p) {
            $name = $p['name'] ?? '';
            $json = json_encode($p['playlist'] ?? null);
            if ($name === '' || $json === null) continue;
            $rc = setiq_post_playlist($name, $json);
            $results[] = [$name, $rc === 200 ? 'imported' : "FAILED (HTTP $rc)"];
        }
        // Report what's on the box so SET:IQ can reconcile its calendar.
        list($ok, $msg, $seqCount) = setiq_sync_sequences($SETIQ_BASE, $key);
        $syncMsg = ($ok ? 'Sequence list synced: ' : 'Sequence sync skipped: ') . $msg;
        // Push the season schedule into FPP's scheduler so the full
        // show run exists, not just the playlists.
        if (!empty($_POST['schedule']) && isset($data['schedule']) && is_array($data['schedule'])) {
            // Create the trigger Command Presets FIRST — the schedule rows
            // fire them by name, so they must exist before fppd reloads.
            $presetNote = '';
            if (isset($data['commandPresets']) && is_array($data['commandPresets']) && $data['commandPresets']) {
                list($pok, $pn) = setiq_apply_command_presets($showName, $data['commandPresets']);
                if ($pok && $pn > 0) $presetNote = " $pn trigger preset" . ($pn === 1 ? '' : 's') . ' set.';
                elseif (!$pok) $presetNote = ' (trigger presets could not be written).';
            }
            list($sok, $smsg) = setiq_update_schedule($showName, $data['schedule']);
            if ($sok) $schedMsg = "FPP schedule updated: $smsg.$presetNote";
            else $schedErr = "FPP schedule not updated: $smsg.";
        }
        // Persist a small snapshot to back the "Last pull" status panel.
        @file_put_contents($pullStatusFile, json_encode([
            'host'       => php_uname('n'),
            'when'       => time(),
            'show'       => $showName,
            'playlists'  => count($data['playlists']),
            'sequences'  => $seqCount,
            'reconciled' => $ok,
        ]));
    } elseif ($action === 'pullone' && $data) {
        // Update just this playlist's content; the schedule is untouched
        // (its entries reference playlists by name).
        $found = false;
        foreach ($data['playlists'] as $p) {
            if (($p['name'] ?? '') !== $pullOneName) continue;
            $found = true;
            $json = json_encode($p['playlist'] ?? null);
            $rc = $json === null ? 0 : setiq_post_playlist($pullOneName, $json);
            $results[] = [$pullOneName, $rc === 200 ? 'imported' : "FAILED (HTTP $rc)"];
            break;
        }
        if (!$found) $error = "SET:IQ has no playlist named \"$pullOneName\" — re-check for updates.";
        $rows = setiq_compare_rows($data);
    } elseif ($action === 'check' && $data) {
        $rows = setiq_compare_rows($data);
    } elseif ($action === 'sync' && !$error) {
        list($ok, $msg) = setiq_sync_sequences($SETIQ_BASE, $key);
        if ($ok) {
            $syncMsg = "Sequence list synced: $msg. In SET:IQ, click "
                . "\"Build catalog from FPP\" to start your show from these "
                . "songs, or \"Sync with FPP\" to reconcile an existing plan.";
        } else {
            $error = "Sequence sync failed: $msg.";
        }
    } elseif ($action === 'import' && !$error) {
        list($ok, $msg) = setiq_push_import($SETIQ_BASE, $key);
        if ($ok) {
            $importMsg = "Sent to SET:IQ: $msg. Open SET:IQ → SET:IQ and click "
                . "\"Import from FPP\" to review and apply it.";
        } else {
            $error = "Import failed: $msg.";
        }
    }
}

// Backing data for the "Last pull" status panel (written after each pull).
$pullStatus = file_exists($pullStatusFile)
            ? json_decode(file_get_contents($pullStatusFile), true) : null;
if (!is_array($pullStatus)) $pullStatus = null;

// The last key the cloud accepted — the live "valid" badge compares the text
// field to this (empty until the first successful Pull/Check).
$verifiedKey = file_exists($verifiedKeyFile)
             ? trim((string) file_get_contents($verifiedKeyFile)) : '';
?>
<div class="container-fluid">
 <div class="iq-pane">
  <h2 class="iq-h2">SET<span class="iq-c-set">:</span>IQ — Pull from SET<span class="iq-c-set">:</span>IQ</h2>
  <p class="iq-lede">Paste your show key from SET:IQ's <b>Send to FPP</b> dialog,
     then Pull to fetch and create every night's playlist. Re-pull whenever you
     change the season in SET:IQ.</p>

  <?php if ($error): ?>
    <div class="setiq-alert setiq-alert-err" style="margin-top:14px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($notice): ?>
    <div class="setiq-alert setiq-alert-ok" style="margin-top:14px"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <?php if ($syncMsg): ?>
    <div class="setiq-alert setiq-alert-ok" style="margin-top:14px"><?= htmlspecialchars($syncMsg) ?></div>
  <?php endif; ?>

  <?php if ($importMsg): ?>
    <div class="setiq-alert setiq-alert-ok" style="margin-top:14px"><?= htmlspecialchars($importMsg) ?></div>
  <?php endif; ?>

  <?php if ($schedMsg): ?>
    <div class="setiq-alert setiq-alert-ok" style="margin-top:14px"><?= htmlspecialchars($schedMsg) ?></div>
  <?php endif; ?>

  <?php if ($schedErr): ?>
    <div class="setiq-alert setiq-alert-err" style="margin-top:14px"><?= htmlspecialchars($schedErr) ?></div>
  <?php endif; ?>

  <?php if ($results): ?>
    <div class="setiq-alert setiq-alert-info" style="margin-top:14px">
      <b>Pulled <?= count($results) ?> playlist(s)<?= $showName ? ' for "' . htmlspecialchars($showName) . '"' : '' ?></b>
      <div class="iq-tablewrap" style="margin-top:8px">
        <table class="table table-striped" style="width:100%">
          <thead><tr><th>Playlist</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($results as $r): ?>
            <tr><td class="iq-name"><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="iq-grid iq-grid-pull">
    <!-- left: key form -->
    <form method="post">
      <label class="iq-label" for="setiq-key">SET:IQ show key</label>
      <div class="iq-keyrow">
        <input type="text" id="setiq-key" name="key" class="iq-input"
               value="<?= htmlspecialchars($key) ?>" placeholder="paste your key" autocomplete="off">
        <!-- Validity tracks the LIVE field against the last key the cloud
             accepted (verified on a successful Pull/Check), not merely whether
             a key is saved — so editing the field updates it immediately. -->
        <span id="setiq-keystate" class="iq-keystate"
              data-verified="<?= htmlspecialchars($verifiedKey, ENT_QUOTES) ?>"></span>
      </div>
      <label class="iq-check">
        <input type="checkbox" name="schedule" value="1" <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['schedule'])) ? 'checked' : '' ?>>
        <span>Also update the FPP schedule (writes one entry per show night;
              your non-SET:IQ schedule entries are kept)</span>
      </label>
      <div class="iq-btnrow">
        <button type="submit" class="buttons btn btn-default" name="action" value="sync"
                title="Report this box's .fseq list (with runtimes + song titles) to IQ Studio — it can build your catalog from these songs or reconcile an existing plan. Nothing on this FPP changes.">Push Sequence List to IQ Studio</button>
        <button type="submit" class="buttons btn btn-success" name="action" value="pull"
                title="Fetch every night's playlist from SET:IQ and create it on this box (and, with the box above checked, write the show schedule)">Pull Playlists and Schedules from SET:IQ</button>
        <button type="submit" class="buttons btn btn-default" name="action" value="import"
                title="Send this box's existing playlists + schedule up to SET:IQ for review — nothing on this FPP changes, and SET:IQ won't overwrite your season until you apply it in the editor">Push Playlists and Schedules to SET:IQ</button>
      </div>
      <div class="iq-btnrow-sub">
        <button type="submit" class="iq-linkbtn" name="action" value="check"
                title="Compare every SET:IQ playlist against this box without changing anything — then pull individual playlists from the results">Check for updates</button>
        <span class="iq-fine">Read-only — preview what's new or changed, then pull individual playlists without overwriting everything.</span>
      </div>
      <p class="iq-fine" style="margin-top:8px;max-width:780px">&ldquo;Push Playlists and Schedules to SET:IQ&rdquo; uploads a review copy of
         this box's show; nothing on this FPP changes, and SET:IQ won't touch
         your season until you apply it in the editor.</p>
    </form>

    <?php if ($key !== '' || $verifiedKey !== ''): ?>
    <!-- Disconnect lives in its own form with no key field, so it can clear
         the saved key without the key-save path re-adding it. -->
    <form method="post" class="iq-btnrow-sub" style="margin-top:10px"
          onsubmit="return confirm('Disconnect this box from SET:IQ / REQ:IQ?\n\nThe show key is removed and the REQ:IQ listener stops. Playlists and schedule entries already on the box are left in place — use Manage Playlists to remove those.');">
      <button type="submit" class="iq-linkbtn" name="action" value="disconnect"
              title="Forget the show key and stop REQ:IQ — fully detaches this box from SET:IQ / REQ:IQ">Disconnect this box</button>
      <span class="iq-fine">Removes the stored show key and stops the REQ:IQ listener. Your playlists/schedule stay; clear those from <b>Manage Playlists</b>.</span>
    </form>
    <?php endif; ?>

    <!-- right: last pull status panel -->
    <div class="iq-panel">
      <div class="iq-panel-title">Last pull</div>
      <?php if ($pullStatus): ?>
        <div class="iq-kvs">
          <div class="iq-kv"><span class="iq-kv-k">Host</span><span class="iq-kv-v"><?= htmlspecialchars($pullStatus['host'] ?? '—') ?></span></div>
          <div class="iq-kv"><span class="iq-kv-k">When</span><span class="iq-kv-v"><?= htmlspecialchars(setiq_pull_when($pullStatus['when'] ?? 0)) ?></span></div>
          <div class="iq-kv"><span class="iq-kv-k">Playlists</span><span class="iq-kv-v"><?= (int) ($pullStatus['playlists'] ?? 0) ?> in sync</span></div>
          <div class="iq-kv"><span class="iq-kv-k">Sequences</span><span class="iq-kv-v"><?= (int) ($pullStatus['sequences'] ?? 0) ?> reported</span></div>
        </div>
        <div class="iq-panel-rule"></div>
        <?php if (!empty($pullStatus['reconciled'])): ?>
          <div class="iq-panel-ok"><span class="iq-dot"></span>Calendar reconciled — nothing missing</div>
        <?php else: ?>
          <div class="iq-fine">Sequence reconcile was skipped on the last pull.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="iq-fine">No pull yet. Paste your key and click
          <b>Pull from SET:IQ</b> to fetch this season's playlists.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($rows): ?>
    <h3 class="iq-h3">Playlists<?= $showName ? ' — ' . htmlspecialchars($showName) : '' ?></h3>
    <div class="iq-tablewrap">
      <table class="table table-striped">
        <thead><tr><th>Playlist</th><th class="iq-num">Items</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="iq-name"><?= htmlspecialchars($r[0]) ?></td>
            <td class="iq-num"><?= (int) $r[1] ?></td>
            <td>
              <?php if ($r[2] === 'up to date'): ?>
                <span class="iq-badge iq-badge-ok">up to date</span>
              <?php elseif ($r[2] === 'new'): ?>
                <span class="iq-badge iq-badge-new">new — not on box</span>
              <?php else: ?>
                <span class="iq-badge iq-badge-upd">update available</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r[2] !== 'up to date'): ?>
                <form method="post" style="margin:0">
                  <button type="submit" class="buttons btn btn-default btn-sm"
                          name="pullone" value="<?= htmlspecialchars($r[0]) ?>"
                          title="Pull only this playlist's content; the schedule is untouched">Pull this playlist</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="iq-fine" style="margin-top:10px">Per-playlist pull updates that
       playlist's content only. &ldquo;Pull from SET:IQ&rdquo; refreshes
       everything, including the FPP schedule and the sequence reconcile.
       Status compares the sequence/media lineup; FPP-computed durations are
       ignored.</p>
  <?php endif; ?>

  <p class="iq-fine" style="margin-top:22px">Your key is stored on this FPP only
     (<code><?= htmlspecialchars($keyFile) ?></code>). Find imported playlists
     under Content Setup &rarr; Playlists and the show run under Content Setup
     &rarr; Scheduler. Pull also reports this box's sequence list to SET:IQ, so
     its calendar can flag songs whose .fseq isn't here yet
     (&ldquo;Sync with FPP&rdquo;).</p>
 </div>
</div>
<script>
// Live key-validity badge: reflect the CURRENT text field, not the saved key.
// Empty → nothing; matches the last cloud-verified key → "valid"; otherwise
// "unverified" until a Pull/Check confirms it. Fixes the badge being stuck on
// the cached value regardless of what's typed.
(function () {
  var input = document.getElementById('setiq-key');
  var badge = document.getElementById('setiq-keystate');
  if (!input || !badge) return;
  var verified = badge.getAttribute('data-verified') || '';
  function render() {
    var v = (input.value || '').trim();
    if (v === '') {
      badge.innerHTML = '';
    } else if (v === verified) {
      badge.innerHTML = '<span class="iq-valid"><span class="iq-dot"></span>valid</span>';
    } else {
      badge.innerHTML = '<span class="iq-unverified" style="display:inline-flex;align-items:center;gap:5px;color:#b8860b;font-size:12px;font-weight:600">' +
        '<span style="width:7px;height:7px;border-radius:50%;background:#e0a800;display:inline-block"></span>unverified — Pull to check</span>';
    }
  }
  input.addEventListener('input', render);
  render();
})();
</script>
