<?php
# Menu entries for the SET:IQ Playlist Importer plugin.
# type 'content' places the entry under the FPP "Content Setup" menu.
$menuEntries = Array(
    Array(
        'text' => 'SET:IQ - Pull from SET:IQ',
        'type' => 'content',
        'page' => 'pull.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'SET:IQ - Import Playlists',
        'type' => 'content',
        'page' => 'content.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'REQ:IQ - Viewer Requests',
        'type' => 'content',
        'page' => 'reqiq.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'SET:IQ - Home',
        'type' => 'help',
        'page' => 'https://github.com/diaquas/fpp-SETIQ',
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
