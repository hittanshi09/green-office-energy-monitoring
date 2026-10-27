<?php
// The local preview serves only public entry points and bundled assets.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$public = ['/', '/index.php', '/admin.php', '/user.php', '/login.php', '/register.php', '/endpoint/auth.php', '/endpoint/register.php', '/endpoint/accounts.php', '/endpoint/save.php', '/endpoint/export.php', '/endpoint/add-reading.php', '/endpoint/delete-reading.php'];
$asset = str_starts_with($path, '/assets/') && !str_contains($path, '..')
    && preg_match('/\.(css|js|js\.map|svg|csv)$/D', $path) && is_file(__DIR__ . $path);
if ($asset) return false;
if (!in_array($path, $public, true)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
require __DIR__ . ($path === '/' ? '/index.php' : $path);
return true;
