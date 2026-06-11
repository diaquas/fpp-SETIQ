<?php
/**
 * SET:IQ Playlist Importer — Content Setup page.
 * Runs ON the FPP host. Lists playlist JSONs sitting in the Uploads folder and,
 * on click, POSTs each to the LOCAL FPP API (127.0.0.1) to create real playlists.
 * Local server-side call => no CORS / mixed-content / PNA concerns.
 */

// FPP populates $settings for wrapped plugin pages; fall back to the default path.
$mediaDir  = (isset($settings['mediaDirectory']) && $settings['mediaDirectory'])
             ? $settings['mediaDirectory'] : '/home/fpp/media';
$uploadDir = "$mediaDir/upload";

function fpp_post_playlist($name, $body) {
    $ch = curl_init('http://127.0.0.1/api/playlist/' . rawurlencode($name));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

$results = [];
if (($_POST['action'] ?? '') === 'import') {
    $files = glob("$uploadDir/*.json") ?: [];
    sort($files);
    foreach ($files as $f) {
        $base = basename($f);
        $raw  = file_get_contents($f);
        $json = json_decode($raw, true);
        // Only treat valid FPP playlist JSON (has a mainPlaylist array) as importable.
        if (!is_array($json) || !isset($json['mainPlaylist'])) {
            $results[] = [$base, 'skipped — not a playlist JSON'];
            continue;
        }
        $name = $json['name'] ?? pathinfo($base, PATHINFO_FILENAME);
        list($code, $resp) = fpp_post_playlist($name, $raw);
        $results[] = [$base, ($code === 200) ? "imported as \"$name\"" : "FAILED (HTTP $code)"];
    }
}

// Build the current list of upload JSONs for display.
$uploads = array_map('basename', glob("$uploadDir/*.json") ?: []);
sort($uploads);
?>
<div class="container-fluid">
  <h2>SET:IQ — Import Playlists</h2>
  <p>Playlist JSON files found in the FPP <b>Uploads</b> folder
     (<code><?= htmlspecialchars($uploadDir) ?></code>).
     Click <b>Import</b> to turn them into real FPP playlists.</p>

  <?php if ($results): ?>
    <div class="alert alert-info" style="border:1px solid #b8daff;background:#e7f3ff;padding:10px 14px;border-radius:6px;max-width:680px">
      <b>Import results</b>
      <table class="table table-striped" style="width:100%;margin-top:6px">
        <thead><tr><th>File</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="import">
    <table class="table table-striped" style="max-width:640px">
      <thead><tr><th>Pending playlist JSONs (<?= count($uploads) ?>)</th></tr></thead>
      <tbody>
        <?php if (!$uploads): ?>
          <tr><td><i>None found. Upload your SET:IQ .json files via Content Setup &rarr; File Manager first.</i></td></tr>
        <?php else: foreach ($uploads as $u): ?>
          <tr><td><?= htmlspecialchars($u) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <button type="submit" class="buttons btn btn-success" <?= $uploads ? '' : 'disabled' ?>>
      Import <?= count($uploads) ?> Playlist<?= count($uploads) == 1 ? '' : 's' ?>
    </button>
  </form>

  <p style="margin-top:1em"><small>After importing, find them under
     Content Setup &rarr; Playlists. Originals remain in the Uploads folder; delete them there if desired.</small></p>
</div>
