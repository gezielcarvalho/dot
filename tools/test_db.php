<?php
$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: 3306;
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'dotproject';

$sslCa = getenv('MYSQL_SSL_CA');
$sslMode = strtoupper(getenv('MYSQL_SSL_MODE') ?: 'REQUIRED');

$mysqli = mysqli_init();
$flags = 0;

// If a CA file is specified and exists, set SSL options
if (!empty($sslCa)) {
    if (!file_exists($sslCa)) {
        echo "Warning: MYSQL_SSL_CA set to '$sslCa' but file does not exist inside the container.\n";
    } else {
        // mysqli_ssl_set(my, key, cert, ca, capath, cipher)
        mysqli_ssl_set($mysqli, NULL, NULL, $sslCa, NULL, NULL);
        $flags |= MYSQLI_CLIENT_SSL;

        if ($sslMode === 'DISABLED') {
            // user explicitly disabled SSL
            $flags = 0;
        } elseif ($sslMode === 'REQUIRED') {
            // Use SSL but do not explicitly verify server cert (best-effort)
            if (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
                $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
        } elseif ($sslMode === 'VERIFY_CA' || $sslMode === 'VERIFY_IDENTITY') {
            // Attempt to enable server cert verification if supported
            if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                @mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            }
            // If VERIFY_IDENTITY is requested but host verification is unavailable in this PHP build,
            // the connection will still run SSL with CA verification if possible.
        }
    }
} elseif ($sslMode === 'VERIFY_CA' || $sslMode === 'VERIFY_IDENTITY') {
    echo "Warning: MYSQL_SSL_MODE set to '$sslMode' but MYSQL_SSL_CA is not set. Falling back to no SSL.\n";
}

// Connect with or without SSL flags
$socket = NULL;
$connected = false;
if ($flags) {
    $connected = @mysqli_real_connect($mysqli, $host, $user, $pass, $db, (int)$port, $socket, $flags);
} else {
    $connected = @mysqli_real_connect($mysqli, $host, $user, $pass, $db, (int)$port);
}

if (!$connected) {
    echo "Connect error: " . mysqli_connect_error() . PHP_EOL;
    exit(1);
}

$sslUsed = ($flags & MYSQLI_CLIENT_SSL) ? 'yes' : 'no';
$sslInfo = mysqli_get_server_info($mysqli);
echo "Connected OK to {$host}:{$port} as {$user} (SSL used: {$sslUsed})\n";
echo "Server info: {$sslInfo}\n";

$mysqli->close();
