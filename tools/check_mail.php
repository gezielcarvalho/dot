<?php
// tools/check_mail.php
// Prints current mail configuration and optionally sends a test message
// Usage: php tools/check_mail.php [--send] [--to=you@example.com]

// Ensure DP_BASE_DIR and base path are defined so includes/config.php doesn't abort when loaded from CLI
if (!defined('DP_BASE_DIR')) {
    define('DP_BASE_DIR', realpath(__DIR__ . '/..'));
}
$baseDir = DP_BASE_DIR;
$baseUrl = '';

require_once __DIR__ . '/../includes/config.php';
require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../classes/libmail.class.php';

$send = in_array('--send', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$to = 'admin@example.com';
foreach ($argv as $a) {
    if (strpos($a, '--to=') === 0) {
        $to = substr($a, 5);
    }
}

// Ensure we have an AppUI stub so Mail->BuildMail can call getVersion()
global $AppUI;
if (!isset($AppUI) || !is_object($AppUI)) {
    class _SimpleAppUI { public function getVersion() { return 'dev'; } }
    $AppUI = new _SimpleAppUI();
}
// Ensure SERVER_ADDR exists (CLI doesn't set this) to avoid warnings and to produce a sane HELO hostname
if (empty($_SERVER['SERVER_ADDR'])) {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}

$m = new Mail();
// Allow direct env overrides for the test (matches include/db_connect behaviour)
if (getenv('MAIL_TRANSPORT') !== false) $m->transport = getenv('MAIL_TRANSPORT');
if (getenv('MAIL_HOST') !== false) $m->host = getenv('MAIL_HOST');
if (getenv('MAIL_PORT') !== false) $m->port = getenv('MAIL_PORT');
if (getenv('MAIL_AUTH') !== false) $m->sasl = in_array(strtolower(getenv('MAIL_AUTH')), ['1','true','yes','on']);
if (getenv('MAIL_SMTP_TLS') !== false) $m->tls = in_array(strtolower(getenv('MAIL_SMTP_TLS')), ['1','true','yes','on']);
if (getenv('MAIL_USER') !== false) $m->username = getenv('MAIL_USER');
if (getenv('MAIL_PASS') !== false) $m->password = getenv('MAIL_PASS');

$cfg = array(
    'transport' => $m->transport,
    'host' => $m->host,
    'port' => $m->port,
    'sasl' => $m->sasl,
    'tls' => $m->tls,
    'username' => $m->username ? '***' : '',
    'defer' => $m->defer,
    'timeout' => array_key_exists('timeout', get_object_vars($m)) ? get_object_vars($m)['timeout'] : '',
);

// Print raw environment values for debugging
$envs = array('MAIL_TRANSPORT','MAIL_HOST','MAIL_PORT','MAIL_AUTH','MAIL_USER','MAIL_PASS','MAIL_SMTP_TLS');
echo "Raw environment values:\n";
foreach ($envs as $e) {
    $val = getenv($e);
    echo " - {$e}: " . ($val === false ? '(not set)' : $val) . "\n";
}

echo "\nEffective mail config:\n";
foreach ($cfg as $k => $v) {
    echo " - {$k}: " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
}

if ($send) {
    echo "Attempting to send test email to {$to}...\n";
    $m->From('dotproject@example.com');
    $m->To($to, true);
    $m->Subject('dotProject test email');
    $m->Body('This is a test message from dotProject via tools/check_mail.php');
    // Enable transaction logging only when --verbose is provided
    if ($verbose) {
        $m->RecordTransaction();
    }
    $ok = $m->Send();
    echo $ok ? "Send reported success.\n" : "Send failed.\n";
    if ($verbose) {
        echo "Transaction log:\n";
        foreach ($m->GetTransactionLog() as $line) {
            echo $line . "\n";
        }
    }
    exit($ok ? 0 : 1);
}

