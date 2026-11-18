<?php
// Every Tool Kits menu page and hook callback registered by name must be defined somewhere in the plugin.
// 2.7.74 dropped the Minify page and save handler while their menu entry and admin-post hook stayed registered.
$root = dirname(__DIR__);
$files = array_merge(array($root . '/tool-kits.php', $root . '/uninstall.php'), glob($root . '/templates/*.php') ?: array());
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) { if ($file->getExtension() === 'php') { $files[] = $file->getPathname(); } }
$defined = array(); $sources = array();
foreach ($files as $file) {
    if (!is_file($file)) { continue; }
    $sources[$file] = file_get_contents($file);
    preg_match_all('/^\s*function\s+([A-Za-z0-9_]+)\s*\(/m', $sources[$file], $matches);
    foreach ($matches[1] as $name) { $defined[strtolower($name)] = true; }
}
$missing = array(); $checked = 0;
foreach ($sources as $file => $source) {
    $relative = substr($file, strlen($root) + 1);
    preg_match_all('/add_(?:sub)?menu_page\((.*?)\);/s', $source, $menus);
    foreach ($menus[1] as $args) {
        preg_match_all("/'(tk_[A-Za-z0-9_]+)'/", $args, $names);
        $callback = end($names[1]);
        if ($callback) { $checked++; if (!isset($defined[strtolower($callback)])) { $missing[] = $relative . ': menu callback ' . $callback; } }
    }
    preg_match_all("/add_(?:action|filter)\(\s*'([^']+)'\s*,\s*'(tk_[A-Za-z0-9_]+)'/", $source, $hooks, PREG_SET_ORDER);
    foreach ($hooks as $hook) {
        $checked++;
        if (!isset($defined[strtolower($hook[2])])) { $missing[] = $relative . ': ' . $hook[1] . ' -> ' . $hook[2]; }
    }
}
if ($checked < 100) { throw new RuntimeException('Hook scan found only ' . $checked . ' registrations; the pattern no longer matches the code.'); }
if ($missing) { throw new RuntimeException("Registered callbacks without a definition:\n" . implode("\n", $missing)); }
echo 'PASS: ' . $checked . " menu and hook callbacks all resolve to defined functions\n";
