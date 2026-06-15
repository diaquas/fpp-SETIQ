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
  <h2>REQ:IQ — Viewer Song Requests</h2>
  <p>Lets viewers control the show from their phones. A background
     listener on this FPP reports what's playing to your REQ:IQ page and
     inserts requested songs into playback. It also runs live transport
     commands (next / pause / volume …) and viewer announcements you fire
     from the REQ:IQ Live console. It uses the same show key as
     <b>Pull from SET:IQ</b>.</p>

  <?php if ($notice): ?>
    <div class="setiq-alert setiq-alert-ok"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <?php if ($key === ''): ?>
    <div class="setiq-alert setiq-alert-err">
      No show key set. Open <b>SET:IQ - Pull from SET:IQ</b> and save your show key first.
    </div>
  <?php endif; ?>

  <table class="table" style="max-width:680px">
    <tr><th style="text-align:left;width:180px">REQ:IQ</th>
        <td><?= $enabled ? '<b class="setiq-ok">Enabled</b>' : '<b class="setiq-muted">Disabled</b>' ?></td></tr>
    <tr><th style="text-align:left">Listener</th>
        <td><?= $pid ? "<b class=\"setiq-ok\">Running</b> (pid $pid)" : '<b class="setiq-err">Not running</b>' ?></td></tr>
    <?php if (is_array($status)): ?>
    <tr><th style="text-align:left">Last heartbeat</th>
        <td><?= htmlspecialchars($status['updatedAt'] ?? '—') ?>
            <?= !empty($status['error']) ? ' — <span class="setiq-err">' . htmlspecialchars($status['error']) . '</span>' : '' ?></td></tr>
    <tr><th style="text-align:left">FPP playback</th>
        <td><?= htmlspecialchars(($status['statusName'] ?? '—') . (!empty($status['playing']) ? ' — ' . $status['playing'] : '')) ?></td></tr>
    <?php if (!empty($status['lastCommand'])): ?>
    <tr><th style="text-align:left">Last transport command</th>
        <td><?= htmlspecialchars($status['lastCommand']) ?></td></tr>
    <?php endif; ?>
    <?php endif; ?>
  </table>

  <form method="post" style="margin:12px 0">
    <?php if ($enabled): ?>
      <button type="submit" class="buttons btn btn-default" name="action" value="disable">Disable REQ:IQ</button>
      <button type="submit" class="buttons btn btn-default" name="action" value="restart">Restart listener</button>
    <?php else: ?>
      <button type="submit" class="buttons btn btn-success" name="action" value="enable" <?= $key === '' ? 'disabled' : '' ?>>Enable REQ:IQ</button>
    <?php endif; ?>
  </form>

  <?php if ($logTail): ?>
    <h4>Recent log</h4>
    <pre class="setiq-log"><?= htmlspecialchars($logTail) ?></pre>
  <?php endif; ?>

  <p><small>The listener starts automatically when FPP boots (while
     enabled), heartbeats every few seconds to
     <code>lightsofelmridge.com</code>, and maintains a
     <code>REQIQ Requests</code> playlist it inserts requested songs
     from. Log: <code><?= htmlspecialchars($logFile) ?></code></small></p>
</div>
