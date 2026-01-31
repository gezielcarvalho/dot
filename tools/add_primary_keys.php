<?php
/**
 * add_primary_keys.php
 *
 * Add an auto-increment primary key column `id` to a list of tables that
 * are expected to have a primary key but were created without one. This is
 * intended as a safe, one-time migration for dev environments where the
 * server enforces `sql_require_primary_key` and the initial schema import
 * ended up creating some tables without PKs.
 *
 * Usage: php tools/add_primary_keys.php
 */

define('DP_BASE_DIR', dirname(__DIR__));
require_once DP_BASE_DIR . '/includes/config.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';

$dbprefix = dPgetConfig('dbprefix','');
$tables = array(
    $dbprefix . 'custom_fields_lists',
    $dbprefix . 'custom_fields_values',
    $dbprefix . 'dotpermissions',
    $dbprefix . 'dpversion',
    $dbprefix . 'forum_visits',
    $dbprefix . 'forum_watch',
    $dbprefix . 'project_contacts',
    $dbprefix . 'project_departments',
    $dbprefix . 'task_contacts',
    $dbprefix . 'task_departments',
    $dbprefix . 'user_events',
    $dbprefix . 'user_preferences',
    $dbprefix . 'user_roles',
);

echo "Adding primary keys where missing. This will modify schema and may be irreversible.\n";

// Try to disable sql_require_primary_key for the session (best-effort)
$origRequirePK = null;
$r = $db->GetOne("SELECT @@SESSION.sql_require_primary_key");
if ($r !== null) {
    $origRequirePK = $r;
    echo "Current session sql_require_primary_key: " . var_export($origRequirePK, true) . "\n";
    try {
        $db->Execute("SET SESSION sql_require_primary_key = 0");
        echo "Disabled session sql_require_primary_key for migration.\n";
    } catch (Exception $e) {
        echo "Warning: could not disable sql_require_primary_key for session: " . $e->getMessage() . "\n";
    }
}

foreach ($tables as $t) {
    echo "\nProcessing table: {$t}\n";
    $exists = intval($db->GetOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->escape($t) . "'"));
    if (!$exists) {
        echo " - Table does not exist, skipping.\n";
        continue;
    }
    $hasPK = intval($db->GetOne("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = '" . $db->escape($t) . "' AND constraint_type = 'PRIMARY KEY'"));
    if ($hasPK) {
        echo " - Table already has a PRIMARY KEY; skipping.\n";
        continue;
    }

    // Add id column as primary key
    try {
        echo " - Adding 'id' INT NOT NULL AUTO_INCREMENT PRIMARY KEY...\n";
        $db->Execute("ALTER TABLE `{$t}` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        echo "   Success.\n";
    } catch (Exception $e) {
        echo "   Failed to add primary key to {$t}: " . $e->getMessage() . "\n";
        // Try a fallback: add id column nullable, populate, then make PK
        try {
            echo "   Trying fallback: add id nullable, populate, set PK...\n";
            $db->Execute("ALTER TABLE `{$t}` ADD COLUMN `id` INT NULL");
            // populate sequential ids
            $db->Execute("SET @rownum = 0");
            $db->Execute("UPDATE `{$t}` SET id = (@rownum := @rownum + 1)");
            $db->Execute("ALTER TABLE `{$t}` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
            echo "   Fallback succeeded.\n";
        } catch (Exception $e2) {
            echo "   Fallback also failed for {$t}: " . $e2->getMessage() . "\n";
            echo "   You may need to resolve duplicates or inspect the table manually.\n";
        }
    }
}

// Restore session sql_require_primary_key if we changed it
if ($origRequirePK !== null) {
    try {
        $db->Execute("SET SESSION sql_require_primary_key = " . ($origRequirePK ? 1 : 0));
        echo "\nRestored session sql_require_primary_key to original value.\n";
    } catch (Exception $e) {
        echo "\nWarning: could not restore sql_require_primary_key: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Please re-run tools/init_permissions.php and tools/regenerate_permissions.php and verify the application UI.\n";

?>