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
/* Theme-neutral: borders/labels key off currentColor so FPP's light
   and dark themes both read correctly. */
.setiq-plugin-tabs {
  display: flex;
  gap: 4px;
  margin: 6px 0 14px;
  border-bottom: 2px solid rgba(127, 127, 127, 0.35);
  align-items: center;
}
.setiq-plugin-tabs a {
  padding: 8px 16px;
  text-decoration: none;
  color: inherit;
  opacity: 0.65;
  border: 1px solid transparent;
  border-bottom: none;
  border-radius: 6px 6px 0 0;
  font-weight: 600;
}
.setiq-plugin-tabs a.active {
  opacity: 1;
  border-color: rgba(127, 127, 127, 0.35);
  background: rgba(127, 127, 127, 0.12);
}
.setiq-plugin-tabs .setiq-plugin-docs {
  margin-left: auto;
  font-size: 12px;
  opacity: 0.7;
}
</style>
<div class="container-fluid">
  <div class="setiq-plugin-tabs">
    <a href="<?= setiq_tab_url('pull') ?>"
       class="<?= $setiqTab === 'pull' ? 'active' : '' ?>">SET:IQ — Pull</a>
    <a href="<?= setiq_tab_url('manage') ?>"
       class="<?= $setiqTab === 'manage' ? 'active' : '' ?>">Manage Playlists</a>
    <a href="<?= setiq_tab_url('reqiq') ?>"
       class="<?= $setiqTab === 'reqiq' ? 'active' : '' ?>">REQ:IQ — Viewer Requests</a>
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
