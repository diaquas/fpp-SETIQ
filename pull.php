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

$SETIQ_BASE = 'https://lightsofelmridge.com';

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

/** On-disk path to a sequence's .fseq, or null. */
function setiq_fseq_path($mediaDir, $name) {
    foreach (['sequences', 'Sequences'] as $sub) {
        $p = "$mediaDir/$sub/$name";
        if (is_file($p)) return $p;
    }
    return null;
}

/**
 * Per-sequence stats (lighting cues, top-3 colors, and run-length lit
 * channel ranges) computed on the box by scripts/fseq_stats.py from the
 * rendered .fseq. The box has no xLights layout (it lives in Studio IQ),
 * so prop attribution is NOT done here — the cloud reconciles the
 * `activity` runs against the uploaded layout. Cached by file signature
 * so unchanged sequences aren't re-scanned, with a wall-clock budget so a
 * first run on a big show never hangs the request — anything not reached
 * this time is picked up (and cached) on the next sync.
 */
function setiq_collect_stats($names, $pluginDir, $cfgDir, $mediaDir) {
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
    $deadline = time() + 240; // total budget for fresh scans
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

/**
 * Report the on-box sequence list to SET:IQ so its calendar can lock
 * songs that aren't here yet ("Sync with FPP" reconcile). Durations
 * and ID3 tags ride along so the cloud can seed REQ:IQ catalog rows
 * with real lengths, titles and artists (standalone mode).
 */
function setiq_sync_sequences($base, $key, $withStats = true) {
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
    // Per-sequence stats from the .fseq (lighting cues, props, fave prop,
    // top-3 colors), cached by file signature on the box. This is the heavy
    // step — gated by the "Grab Key Metrics from FSEQ Files" toggle so a
    // plain reconcile (names + runtimes + ID3) stays fast.
    $stats = [];
    if ($withStats) {
        global $cfgDir;
        $stats = setiq_collect_stats($names, __DIR__, $cfgDir, dirname($cfgDir));
    }

    $payload = json_encode([
        'key'       => $key,
        'sequences' => $names,
        'durations' => (object) $durations,
        'id3'       => (object) $id3,
        'stats'     => (object) $stats,
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
function setiq_update_schedule($showName, $entries) {
    list($code, $body) = setiq_get_json('http://127.0.0.1/api/schedule');
    if ($code !== 200) return [false, "could not read the FPP schedule (HTTP $code)"];
    $existing = json_decode($body, true);
    if (!is_array($existing)) $existing = [];

    // Same character rules as SET:IQ's playlist naming (FPP-safe names).
    $prefix = trim(preg_replace('/[^-a-zA-Z0-9_ ]/', '', $showName)) . ' - ';
    $kept = [];
    $replaced = 0;
    foreach ($existing as $e) {
        $pl = is_array($e) ? ($e['playlist'] ?? '') : '';
        if ($prefix !== ' - ' && $pl !== '' && strpos($pl, $prefix) === 0) {
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
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404) return [false, 'SET:IQ didn\'t recognize the key'];
    if ($code !== 200) return [false, "SET:IQ rejected the import (HTTP $code)"];
    return [true, count($playlists) . ' playlist(s) and ' . count($schedule)
        . ' schedule entr' . (count($schedule) === 1 ? 'y' : 'ies')
        . ' sent to SET:IQ'];
}

/** Fetch the show's playlists (+ schedule) from the SET:IQ cloud. */
function setiq_fetch_cloud($base, $key) {
    // Self-report the hostname so SET:IQ's dialog can show
    // "last pulled by <host>".
    list($code, $body) = setiq_get_json(
        "$base/api/setiq/fpp/playlists?key=" . rawurlencode($key),
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

// Save the key when submitted.
$key = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : '';
if (isset($_POST['key'])) {
    $key = trim($_POST['key']);
    @file_put_contents($keyFile, $key);
}

$results = [];
$rows = [];
$showName = '';
$error = '';
$syncMsg = '';
$schedMsg = '';
$schedErr = '';
$importMsg = '';
$action = $_POST['action'] ?? '';
// Per-playlist Pull buttons submit their playlist name as "pullone".
$pullOneName = isset($_POST['pullone']) && is_string($_POST['pullone'])
             ? trim($_POST['pullone']) : '';
if ($pullOneName !== '') $action = 'pullone';
// "Grab Key Metrics from FSEQ Files" — when on, the sequence reconcile runs
// the heavy on-box .fseq parse for colors/key-moments/prop metrics.
$withMetrics = !empty($_POST['metrics']);

if (in_array($action, ['pull', 'check', 'pullone', 'sync', 'import'], true)) {
    $data = null;
    if ($key === '') {
        $error = 'Enter your SET:IQ show key first.';
    } elseif ($action !== 'sync' && $action !== 'import') {
        list($error, $data) = setiq_fetch_cloud($SETIQ_BASE, $key);
        if ($data) $showName = $data['show'] ?? '';
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
        list($ok, $msg, $seqCount) = setiq_sync_sequences($SETIQ_BASE, $key, $withMetrics);
        $syncMsg = ($ok ? 'Sequence list synced: ' : 'Sequence sync skipped: ') . $msg;
        // Push the season schedule into FPP's scheduler so the full
        // show run exists, not just the playlists.
        if (!empty($_POST['schedule']) && isset($data['schedule']) && is_array($data['schedule'])) {
            list($sok, $smsg) = setiq_update_schedule($showName, $data['schedule']);
            if ($sok) $schedMsg = "FPP schedule updated: $smsg.";
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
        list($ok, $msg) = setiq_sync_sequences($SETIQ_BASE, $key, $withMetrics);
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
        <?php if ($key !== ''): ?>
          <span class="iq-valid"><span class="iq-dot"></span>valid</span>
        <?php endif; ?>
      </div>
      <label class="iq-check"
             title="Grabs metrics (colors used; key moments; total props used; and top used prop) to showcase to your REQ:IQ audience.">
        <input type="checkbox" name="metrics" value="1" <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['metrics'])) ? 'checked' : '' ?>>
        <span>Grab Key Metrics from FSEQ Files
              <span class="iq-help-dot" aria-hidden="true">?</span></span>
      </label>
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
