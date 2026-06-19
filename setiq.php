<?php
/**
 * SET:IQ / REQ:IQ — single plugin page with tabs, so the plugin adds
 * exactly one entry to FPP's Content Setup menu.
 *
 *   ?tab=pull    → Pull from SET:IQ (playlists + schedule, key auth)
 *   ?tab=manage  → Manage the SET:IQ playlists on this box
 *   ?tab=reqiq   → REQ:IQ viewer requests (listener settings + status)
 *
 * The tab pages keep their own logic; this wrapper only renders the
 * nav and includes the right one. Forms post back to the current URL,
 * so the active tab survives submissions.
 */

$pluginName = 'fpp-SETIQ';
$setiqTab = in_array($_GET['tab'] ?? '', ['reqiq', 'manage'], true)
    ? $_GET['tab']
    : 'pull';

function setiq_tab_url($tab) {
    global $pluginName;
    return "plugin.php?_menu=content&plugin=" . rawurlencode($pluginName)
         . "&page=setiq.php&tab=" . rawurlencode($tab);
}
?>
<style>
/* IQ Studio shared styles. Sourced from css/iqstudio.css and inlined so
   the look loads reliably regardless of how the host serves plugin
   assets. FPP 10's "Theme" setting puts data-bs-theme="dark" on <html>
   (the hook the stylesheet's dark palette scopes to); FPP <= 9 has no
   such attribute and gets the light palette, matching its always-light
   UI. */
<?php @readfile(__DIR__ . '/css/iqstudio.css'); ?>
</style>
<div class="container-fluid">
  <div class="setiq-plugin-tabs">
    <a href="<?= setiq_tab_url('pull') ?>"
       class="iq-tab-set <?= $setiqTab === 'pull' ? 'active' : '' ?>">SET<span class="iq-c-set">:</span>IQ — Pull</a>
    <a href="<?= setiq_tab_url('manage') ?>"
       class="iq-tab-set <?= $setiqTab === 'manage' ? 'active' : '' ?>">Manage Playlists</a>
    <a href="<?= setiq_tab_url('reqiq') ?>"
       class="iq-tab-req <?= $setiqTab === 'reqiq' ? 'active' : '' ?>">REQ<span class="iq-c-req">:</span>IQ — Requests</a>
    <a class="setiq-plugin-docs" href="https://github.com/diaquas/fpp-SETIQ"
       target="_blank" rel="noopener noreferrer">Docs &amp; help ↗</a>
  </div>
</div>
<?php
// On a POST (a pull / push / sync), the tab page below runs the slow work —
// cloud round-trips plus a per-sequence .fseq scan, up to a minute — BEFORE it
// emits any content. FPP has already flushed the chrome + tabs, so the content
// area would otherwise sit blank/white for that whole time. Emit a styled
// loading dialog NOW and push it to the browser, so the operator sees real
// progress during the wait; it removes itself once the finished page loads.
// (The on-submit overlay in busy.inc.php covers the click on the *previous*
// page; this covers the server processing of the *response* page.)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $busyMsg = 'Working...';
    if (isset($_POST['pullone'])) {
        $busyMsg = 'Pulling this playlist...';
    } else {
        $busyMap = [
            'sync'    => 'Pushing your sequence list to IQ Studio...',
            'pull'    => 'Pulling playlists and schedules from SET:IQ...',
            'import'  => 'Pushing playlists and schedules to SET:IQ...',
            'check'   => 'Checking SET:IQ for updates...',
            'delete'  => 'Updating playlists...',
            'enable'  => 'Updating the REQ:IQ listener...',
            'disable' => 'Updating the REQ:IQ listener...',
            'restart' => 'Updating the REQ:IQ listener...',
        ];
        $a = strtolower((string) ($_POST['action'] ?? ''));
        if (isset($busyMap[$a])) $busyMsg = $busyMap[$a];
    }
    ?>
    <div id="setiq-busy-load" role="status" aria-live="polite">
      <div class="sbl-card">
        <div class="sbl-spin" aria-hidden="true"></div>
        <div class="sbl-bar"><span></span></div>
        <div class="sbl-msg"><?= htmlspecialchars($busyMsg) ?></div>
        <div class="sbl-sub">This can take up to a minute. Please keep this page open.</div>
      </div>
    </div>
    <style>
    /* Self-contained — the shared iqstudio.css / busy.inc.php styles load later
       in the stream, so this dialog carries its own look to render instantly. */
    #setiq-busy-load{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(15,17,21,.6)}
    #setiq-busy-load .sbl-card{background:#fff;color:#1c1b19;border-radius:12px;padding:24px 28px;max-width:380px;width:90%;text-align:center;box-shadow:0 18px 50px -12px rgba(0,0,0,.55);font-family:system-ui,-apple-system,sans-serif}
    #setiq-busy-load .sbl-spin{width:34px;height:34px;margin:0 auto 14px;border-radius:50%;border:3px solid rgba(128,128,128,.3);border-top-color:#2faa5a;animation:sbl-spin .8s linear infinite}
    @keyframes sbl-spin{to{transform:rotate(360deg)}}
    #setiq-busy-load .sbl-bar{height:6px;border-radius:4px;background:rgba(128,128,128,.22);overflow:hidden;margin:4px 0 12px}
    #setiq-busy-load .sbl-bar>span{display:block;height:100%;width:40%;border-radius:4px;background:#2faa5a;animation:sbl-slide 1.1s ease-in-out infinite}
    @keyframes sbl-slide{0%{margin-left:-45%}100%{margin-left:100%}}
    #setiq-busy-load .sbl-msg{font-weight:700;font-size:15px}
    #setiq-busy-load .sbl-sub{font-size:12.5px;opacity:.7;margin-top:6px}
    </style>
    <script>
    window.addEventListener('load', function () {
      var o = document.getElementById('setiq-busy-load');
      if (o && o.parentNode) o.parentNode.removeChild(o);
    });
    </script>
    <?php
    // Push the dialog out ahead of the slow include so the browser paints it
    // now instead of after the work finishes.
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

if ($setiqTab === 'reqiq') {
    include __DIR__ . '/reqiq.php';
} elseif ($setiqTab === 'manage') {
    include __DIR__ . '/manage.php';
} else {
    include __DIR__ . '/pull.php';
}

// On-submit overlay: covers the click on the current page until navigation.
include __DIR__ . '/busy.inc.php';
