<?php
// Cache and raw-file I/O helpers (CA9, CA14, CP1)

function isCacheValid(string $file, int $maxAge): bool {
    return $maxAge > 0
        && file_exists($file)
        && (time() - filemtime($file)) < $maxAge;
}

function readCache(string $file): string {
    return file_exists($file) ? file_get_contents($file) : '';
}

function writeCache(string $file, string $data): void {
    $dir = dirname($file);
    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, $data);
}

function markCacheOutdated(string $file): void {
    $dir = dirname($file);
    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!file_exists($file)) {
        file_put_contents($file, '');
    }

    touch($file);
}

function appendRaw(string $file, string $line): void {
    $dir = dirname($file);
    if ($dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($file, rtrim($line) . "\n", FILE_APPEND | LOCK_EX);
}

