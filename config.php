<?php
// Database settings. Easiest options:
//   1) Create a file named config.local.php next to this one (see config.local.sample.php), or
//   2) Set environment variables (Render etc.): DATABASE_URL, or DB_DRIVER/DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS.

$c = [
    'driver' => getenv('DB_DRIVER') ?: 'mysql',   // 'mysql' or 'pgsql'
    'host'   => getenv('DB_HOST') ?: 'localhost',
    'port'   => getenv('DB_PORT') ?: '',
    'name'   => getenv('DB_NAME') ?: 'task_api',
    'user'   => getenv('DB_USER') ?: 'root',
    'pass'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
];

$url = getenv('DATABASE_URL');
if ($url) {
    $p = parse_url($url);
    $c['driver'] = in_array($p['scheme'] ?? '', ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql';
    $c['host']   = $p['host'] ?? $c['host'];
    $c['port']   = isset($p['port']) ? (string) $p['port'] : '';
    $c['user']   = isset($p['user']) ? urldecode($p['user']) : $c['user'];
    $c['pass']   = isset($p['pass']) ? urldecode($p['pass']) : '';
    $c['name']   = isset($p['path']) ? ltrim($p['path'], '/') : $c['name'];
}

// Local file (shared hosting): overrides everything above. Never commit it to GitHub.
if (is_file(__DIR__ . '/config.local.php')) {
    $c = array_merge($c, require __DIR__ . '/config.local.php');
}

return $c;
