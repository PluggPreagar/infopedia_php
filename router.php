<?php
// Router for `php -S` built-in server.
// Maps /foo → foo.php when no extension is given; falls through otherwise.

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$route  = ltrim($path, '/');
$root   = __DIR__;

// Empty path → index
if ($route === '') {
    require $root . '/index.php';
    return true;
}

// Exact file exists → let built-in server handle it (runs .php, serves static)
if (is_file($root . '/' . $route)) {
    return false;
}

// No extension given → try adding .php (e.g. /entries → entries.php)
if (pathinfo($route, PATHINFO_EXTENSION) === '') {
    $php = $root . '/' . $route . '.php';
    if (is_file($php)) {
        require $php;
        return true;
    }
}

// Nothing matched → 404
return false;
