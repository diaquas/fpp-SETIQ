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
/* Theme tokens. FPP 10's "Theme" setting (themeOverride: System
   Default / Light / Dark) puts data-bs-theme="dark" on <html> — the
   same hook fpp-dark.css scopes to — so the dark palette below follows
   that setting (including System Default, which tracks the OS). On
   FPP ≤ 9 the attribute never exists and the light palette applies,
   matching its always-light UI. */
:root {
  --setiq-ok:    #1a7f37;
  --setiq-info:  #0969da;
  --setiq-warn:  #9a6700;
  --setiq-err:   #cf222e;
  --setiq-muted: #57606a;
  --setiq-box-border: rgba(127, 127, 127, 0.4);
}
[data-bs-theme="dark"] {
  --setiq-ok:    #3fb950;
  --setiq-info:  #58a6ff;
  --setiq-warn:  #d29922;
  --setiq-err:   #f85149;
  --setiq-muted: #8b949e;
}
.setiq-ok    { color: var(--setiq-ok); }
.setiq-info  { color: var(--setiq-info); }
.setiq-warn  { color: var(--setiq-warn); }
.setiq-err   { color: var(--setiq-err); }
.setiq-muted { color: var(--setiq-muted); }

/* Message boxes. Deliberately NOT FPP/Bootstrap .alert — those carry
   their own text/background colors per theme and fighting them is what
   made light mode unreadable. Tint via rgba so the page background
   shows through on both themes; text always inherits the body color. */
.setiq-alert {
  border: 1px solid var(--setiq-box-border);
  border-left: 4px solid var(--setiq-muted);
  background: rgba(127, 127, 127, 0.1);
  color: inherit;
  padding: 10px 14px;
  border-radius: 6px;
  max-width: 720px;
  margin: 0 0 10px;
}
.setiq-alert-ok   { border-left-color: var(--setiq-ok);   background: rgba(63, 185, 80, 0.1); }
.setiq-alert-err  { border-left-color: var(--setiq-err);  background: rgba(248, 81, 73, 0.1); }
.setiq-alert-info { border-left-color: var(--setiq-info); background: rgba(88, 166, 255, 0.1); }

.setiq-log {
  max-width: 880px;
  max-height: 260px;
  overflow: auto;
  background: rgba(127, 127, 127, 0.12);
  border: 1px solid var(--setiq-box-border);
  border-radius: 6px;
  padding: 10px;
  font-size: 12px;
  color: inherit;
}

/* Tab bar keys off currentColor so it reads on either theme. */
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
