<?php
/**
 * grant_admin_permissions.php
 *
 * Grant full permissions to a specified user (default user_id = 1) by
 * inserting allow entries into the dotpermissions table for all available
 * axo/permission combinations found in the gacl tables.
 *
 * Usage: php tools/grant_admin_permissions.php [user_id]
 */

define('DP_BASE_DIR', dirname(__DIR__));
require_once DP_BASE_DIR . '/includes/config.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';

$admin_id = isset($argv[1]) && is_numeric($argv[1]) ? intval($argv[1]) : 1;
$dbprefix = dPgetConfig('dbprefix','');

echo "Granting full permissions for user_id={$admin_id}\n";

// Remove explicit denies for the user to avoid precedence issues
$delSql = "DELETE FROM {$dbprefix}dotpermissions WHERE user_id = '" . $admin_id . "' AND allow = 0";
try {
    $db->Execute($delSql);
    echo "Removed explicit denies for user {$admin_id}\n";
} catch (Exception $e) {
    echo "Warning: failed to remove denies: " . $e->getMessage() . "\n";
}

// Insert allow entries for all known axo/permission combos based on gacl mappings
$insertSql = "INSERT INTO {$dbprefix}dotpermissions (acl_id,user_id,section,axo,permission,allow,priority,enabled)
SELECT 0, '" . $admin_id . "', axo.section_value, axo.value, aco.value, 1, 1, 1
FROM {$dbprefix}gacl_aco_map aco_m
JOIN {$dbprefix}gacl_aco aco ON aco_m.value = aco.value
JOIN {$dbprefix}gacl_axo_map axo_m ON aco_m.acl_id = axo_m.acl_id
JOIN {$dbprefix}gacl_axo axo ON axo_m.value = axo.value
GROUP BY axo.section_value, axo.value, aco.value
ON DUPLICATE KEY UPDATE allow = VALUES(allow), enabled = VALUES(enabled), priority = VALUES(priority)";

try {
    $db->Execute($insertSql);
    echo "Inserted allow entries for user {$admin_id}\n";
} catch (Exception $e) {
    echo "Error inserting allow entries: " . $e->getMessage() . "\n";
    echo "Attempting fallback insert using NOT EXISTS clause...\n";
    $fallbackSql = "INSERT INTO {$dbprefix}dotpermissions (acl_id,user_id,section,axo,permission,allow,priority,enabled)
SELECT 0, '" . $admin_id . "', axo.section_value, axo.value, aco.value, 1, 1, 1
FROM {$dbprefix}gacl_aco_map aco_m
JOIN {$dbprefix}gacl_aco aco ON aco_m.value = aco.value
JOIN {$dbprefix}gacl_axo_map axo_m ON aco_m.acl_id = axo_m.acl_id
JOIN {$dbprefix}gacl_axo axo ON axo_m.value = axo.value
WHERE NOT EXISTS (
    SELECT 1 FROM {$dbprefix}dotpermissions dp
    WHERE dp.user_id = '" . $admin_id . "' AND dp.section = axo.section_value
      AND dp.axo = axo.value AND dp.permission = aco.value
)
GROUP BY axo.section_value, axo.value, aco.value";
    try {
        $db->Execute($fallbackSql);
        echo "Fallback insert completed for user {$admin_id}\n";
    } catch (Exception $e2) {
        echo "Fallback also failed: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

// Optionally show a small summary
$count = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}dotpermissions WHERE user_id = '" . $admin_id . "'");
echo "Total permission rows for user {$admin_id}: " . intval($count) . "\n";

echo "Done. You may need to clear any application cache or restart web server to see changes take effect.\n";

?>