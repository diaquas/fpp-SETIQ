<?php
/**
 * REQ:IQ — viewer song requests, settings + status page.
 *
 * Enables/disables the background listener (reqiq_listener.php) that
 * connects this FPP to the REQ:IQ cloud: viewers see what's playing and
 * request songs from their phones; the listener inserts winning
 * requests into playback. Uses the same show key as SET:IQ pull.
 */

$pluginName = 'fpp-SETIQ';
$pluginDir  = "/home/fpp/media/plugins/$pluginName";
$cfgDir  = (isset($settings['configDirectory']) && $settings['configDirectory'])
           ? $settings['configDirectory'] : '/home/fpp/media/config';
$keyFile    = "$cfgDir/$pluginName.key";
$flagFile   = "$cfgDir/$pluginName.reqiq";
$statusFile = "$cfgDir/$pluginName.reqiq-status.json";
$pidFile    = "/tmp/$pluginName-reqiq.pid";
$logFile    = "/home/fpp/media/logs/$pluginName-reqiq.log";

function reqiq_pid($pidFile) {
    if (!file_exists($pidFile)) return 0;
    $pid = (int) trim(file_get_contents($pidFile));
    return ($pid > 0 && file_exists("/proc/$pid")) ? $pid : 0;
}

function reqiq_start($pluginDir, $logFile) {
    // setsid + closed stdin fully detaches the listener from the web
    // request, so apache reaping the request can't take it down.
    exec('setsid nohup /usr/bin/php ' . escapeshellarg("$pluginDir/reqiq_listener.php")
       . ' < /dev/null >> ' . escapeshellarg($logFile) . ' 2>&1 &');
    usleep(500000); // give it a beat so the status below reflects reality
}

$key     = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : '';
$enabled = file_exists($flagFile)
        && preg_match('/^enabled=1/m', file_get_contents($flagFile));
$notice  = '';

$action = $_POST['action'] ?? '';
if ($action === 'enable') {
    @file_put_contents($flagFile, "enabled=1\n");
    $enabled = true;
    if (!reqiq_pid($pidFile)) reqiq_start($pluginDir, $logFile);
    $notice = 'REQ:IQ enabled — the listener starts with FPP and is running now.';
} elseif ($action === 'disable') {
    @file_put_contents($flagFile, "enabled=0\n");
    $enabled = false;
    // Listener exits on its own next tick; nudge it if it's mid-sleep.
    $pid = reqiq_pid($pidFile);
    if ($pid) { exec('kill ' . (int) $pid); @unlink($pidFile); }
    $notice = 'REQ:IQ disabled — the listener has been stopped.';
} elseif ($action === 'restart') {
    $pid = reqiq_pid($pidFile);
    if ($pid) { exec('kill ' . (int) $pid); @unlink($pidFile); }
    if ($enabled) reqiq_start($pluginDir, $logFile);
    $notice = $enabled ? 'Listener restarted.' : 'Enable REQ:IQ first.';
}

// Self-heal: enabled but not running (fresh install, crashed listener,
// fppd restart that beat the flag) → start it right now. Any visit to
// this page brings the listener back without operator surgery.
if ($enabled && !reqiq_pid($pidFile)) {
    reqiq_start($pluginDir, $logFile);
    if (!$notice) $notice = 'Listener was not running — started it.';
}

$pid    = reqiq_pid($pidFile);
$status = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
$logTail = file_exists($logFile)
         ? implode("", array_slice(file($logFile), -15)) : '';
?>
<div class="container-fluid">
 <div class="iq-pane">
  <h2 class="iq-h2">REQ<span class="iq-c-req">:</span>IQ — Viewer Song Requests</h2>
  <p class="iq-lede">A background listener on this FPP reports what's playing to
     your REQ:IQ page and inserts requested songs into playback. It also carries
     out the transport commands and announcements your operators fire from the
     cloud REQ:IQ page — this listener just relays them. Uses the same show key
     as <b>Pull from SET:IQ</b>.</p>

  <?php if ($notice): ?>
    <div class="setiq-alert setiq-alert-ok" style="margin-top:14px"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <?php if ($key === ''): ?>
    <div class="setiq-alert setiq-alert-err" style="margin-top:14px">
      No show key set. Open <b>SET:IQ — Pull</b> and save your show key first.
    </div>
  <?php endif; ?>

  <div class="iq-grid iq-grid-even">
    <!-- left: listener status -->
    <div class="iq-panel">
      <div class="iq-panel-title">Listener status</div>
      <div class="iq-kvs">
        <div class="iq-kv">
          <span class="iq-kv-k">REQ:IQ</span>
          <?= $enabled ? '<span class="iq-pill-ok">Enabled</span>' : '<span class="iq-pill-off">Disabled</span>' ?>
        </div>
        <div class="iq-kv">
          <span class="iq-kv-k">Listener</span>
          <?= $pid ? '<span class="iq-pill-ok">Running · pid ' . (int) $pid . '</span>' : '<span class="iq-pill-err">Not running</span>' ?>
        </div>
        <?php if (is_array($status)): ?>
          <div class="iq-kv">
            <span class="iq-kv-k">Last heartbeat</span>
            <span class="iq-kv-v<?= !empty($status['error']) ? ' iq-err' : '' ?>"><?= htmlspecialchars($status['updatedAt'] ?? '—') ?><?= !empty($status['error']) ? ' — ' . htmlspecialchars($status['error']) : '' ?></span>
          </div>
          <div class="iq-kv">
            <span class="iq-kv-k">FPP playback</span>
            <span class="iq-kv-v"><?= htmlspecialchars(($status['statusName'] ?? '—') . (!empty($status['playing']) ? ' — ' . $status['playing'] : '')) ?></span>
          </div>
          <?php if (!empty($status['lastCommand'])): ?>
          <div class="iq-kv">
            <span class="iq-kv-k">Last transport</span>
            <span class="iq-kv-v"><?= htmlspecialchars($status['lastCommand']) ?></span>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <form method="post" class="iq-panel-foot">
        <?php if ($enabled): ?>
          <button type="submit" class="buttons btn btn-default" name="action" value="disable">Disable REQ:IQ</button>
          <button type="submit" class="buttons btn btn-default" name="action" value="restart">Restart listener</button>
        <?php else: ?>
          <button type="submit" class="buttons btn btn-success" name="action" value="enable" <?= $key === '' ? 'disabled' : '' ?>>Enable REQ:IQ</button>
        <?php endif; ?>
      </form>
    </div>

    <!-- right: cloud console pointer + recent log -->
    <div>
      <div class="iq-panel-blue">
        <div class="iq-blue-h">Running the show live</div>
        <p class="iq-blue-p">Now-playing, transport controls, viewer announcements
           and the request queue live on your <b>REQ:IQ viewer page</b> in the
           cloud. This listener relays them to FPP — there's nothing to operate
           here.</p>
        <a href="https://lightsofelmridge.com" target="_blank" rel="noopener" class="iq-btn-req">Open REQ:IQ page ↗</a>
      </div>
      <?php if ($logTail): ?>
        <div class="iq-panel-title" style="margin:20px 0 10px">Recent log</div>
        <pre class="iq-pre"><?= htmlspecialchars($logTail) ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <p class="iq-fine" style="margin-top:22px">The listener starts automatically
     when FPP boots (while enabled), heartbeats every few seconds to
     <code>lightsofelmridge.com</code>, and maintains a <code>REQIQ Requests</code>
     playlist it inserts requested songs from.
     Log: <code><?= htmlspecialchars($logFile) ?></code></p>
 </div>
</div>
