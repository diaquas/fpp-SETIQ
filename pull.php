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

$SETIQ_BASE = 'https://lightsofelmridge.com';

function setiq_get_json($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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

// Save the key when submitted.
$key = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : '';
if (isset($_POST['key'])) {
    $key = trim($_POST['key']);
    @file_put_contents($keyFile, $key);
}

$results = [];
$showName = '';
$error = '';
if (($_POST['action'] ?? '') === 'pull') {
    if ($key === '') {
        $error = 'Enter your SET:IQ show key first.';
    } else {
        list($code, $body) = setiq_get_json("$SETIQ_BASE/api/setiq/fpp/playlists?key=" . rawurlencode($key));
        $data = json_decode($body, true);
        if ($code !== 200 || !is_array($data) || !isset($data['playlists'])) {
            $error = "Couldn't reach SET:IQ or the key is invalid (HTTP $code).";
        } else {
            $showName = $data['show'] ?? '';
            foreach ($data['playlists'] as $p) {
                $name = $p['name'] ?? '';
                $json = json_encode($p['playlist'] ?? null);
                if ($name === '' || $json === null) continue;
                $rc = setiq_post_playlist($name, $json);
                $results[] = [$name, $rc === 200 ? 'imported' : "FAILED (HTTP $rc)"];
            }
        }
    }
}
?>
<div class="container-fluid">
  <h2>SET:IQ — Pull from SET:IQ</h2>
  <p>Paste your show key from SET:IQ (<b>Send to FPP</b> dialog), then click
     <b>Pull</b> to fetch and create every night's playlist. Re-pull whenever
     you change the season in SET:IQ.</p>

  <?php if ($error): ?>
    <div class="alert alert-danger" style="border:1px solid #f5c6cb;background:#fdecea;padding:10px 14px;border-radius:6px;max-width:680px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($results): ?>
    <div class="alert alert-info" style="border:1px solid #b8daff;background:#e7f3ff;padding:10px 14px;border-radius:6px;max-width:680px">
      <b>Pulled <?= count($results) ?> playlist(s)<?= $showName ? ' for "' . htmlspecialchars($showName) . '"' : '' ?></b>
      <table class="table table-striped" style="width:100%;margin-top:6px">
        <thead><tr><th>Playlist</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form method="post" style="max-width:640px">
    <label for="setiq-key"><b>SET:IQ show key</b></label><br>
    <input type="text" id="setiq-key" name="key" value="<?= htmlspecialchars($key) ?>"
           placeholder="paste your key" style="width:100%;padding:7px 9px;margin:6px 0 12px" autocomplete="off">
    <input type="hidden" name="action" value="pull">
    <button type="submit" class="buttons btn btn-success">Pull from SET:IQ</button>
  </form>

  <p style="margin-top:1em"><small>Your key is stored on this FPP only
     (<code><?= htmlspecialchars($keyFile) ?></code>). Find imported playlists under
     Content Setup &rarr; Playlists.</small></p>
</div>
