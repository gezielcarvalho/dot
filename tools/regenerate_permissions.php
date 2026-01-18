<?php
/**
 * regenerate_permissions.php
 *
 * Invokes the permissions regeneration routine and prints diagnostics of
 * gacl and dotpermissions tables so you can verify admin permissions.
 *
 * Usage: php tools/regenerate_permissions.php [user_id]
 */

define('DP_BASE_DIR', dirname(__DIR__));

// Minimal bootstrap for CLI
$baseDir = DP_BASE_DIR;
$baseUrl = '';
require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/config.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';
require_once DP_BASE_DIR . '/classes/permissions.class.php';

$checkUser = isset($argv[1]) && is_numeric($argv[1]) ? intval($argv[1]) : 1;
$dbprefix = dPgetConfig('dbprefix','');

echo "Running permissions regeneration...\n";
try {
    $acl = new dPacl();
    $acl->regeneratePermissions();
    echo "regeneratePermissions() completed successfully.\n";
} catch (Exception $e) {
    echo "Error while regenerating permissions: " . $e->getMessage() . "\n";
    exit(1);
}

// Print some diagnostics
$tables = [
    'gacl_aro', 'gacl_aro_groups', 'gacl_axo', 'gacl_axo_groups', 'gacl_aco', 'gacl_acl', 'dotpermissions'
];
foreach ($tables as $t) {
    $cnt = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}{$t}");
    echo str_pad($t, 20) . ": " . intval($cnt) . "\n";
}

// Show a few dotpermissions rows for the user
echo "\nSample dotpermissions rows for user {$checkUser}:\n";
$rows = $db->GetArray("SELECT user_id, section, axo, permission, allow, priority, enabled FROM {$dbprefix}dotpermissions WHERE user_id = '" . $checkUser . "' LIMIT 30");
if (!$rows) {
    echo "No rows found for user {$checkUser}.\n";
} else {
    foreach ($rows as $r) {
        printf("%s | %s | %s | %s | allow=%d pri=%d en=%d\n", $r['user_id'], $r['section'], $r['axo'], $r['permission'], $r['allow'], $r['priority'], $r['enabled']);
    }
}

// Check if a specific app permission 'app' 'admin' 'access' exists for user
$hasAdmin = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}dotpermissions WHERE user_id = '" . $checkUser . "' AND section = 'app' AND axo = 'admin' AND permission = 'access' AND allow = 1");
if ($hasAdmin) {
    echo "\nUser {$checkUser} has 'app/admin/access' permission.\n";
} else {
    echo "\nWARNING: user {$checkUser} DOES NOT have 'app/admin/access' permission.\n";
    echo "You may need to ensure gacl entries exist and that user is a member of the Administrator ARO group.\n";
}

echo "\nDone. If admin permissions are still missing, check gacl_* tables for proper ARO/AXO/ACO records and group mappings.\n";

?>