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
if ($setiqTab === 'reqiq') {
    include __DIR__ . '/reqiq.php';
} elseif ($setiqTab === 'manage') {
    include __DIR__ . '/manage.php';
} else {
    include __DIR__ . '/pull.php';
}
