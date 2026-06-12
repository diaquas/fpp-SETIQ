<?php
# One menu entry — the page itself tabs between SET:IQ pull, playlist
# management, and REQ:IQ viewer requests.
$menuEntries = Array(
    Array(
        'text' => 'SET:IQ / REQ:IQ',
        'type' => 'content',
        'page' => 'setiq.php',
        'wrap' => 1
    )
);

# Standard FPP menu renderer (expects $plugin and $menu to be set by FPP).
foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu) continue;
    if (preg_match('/^http.?:\/\//', $entry['page'])) {
        printf("<li><a href='%s' target='_blank'>%s</a></li>\n", $entry['page'], $entry['text']);
    } else {
        $nopage = (isset($entry['wrap']) && $entry['wrap'] == 0) ? '&nopage=1' : '';
        printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
            $plugin, $entry['page'], $nopage, $entry['text']);
    }
}
?>
