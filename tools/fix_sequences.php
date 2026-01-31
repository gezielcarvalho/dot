<?php
/**
 * fix_sequences.php
 *
 * Normalize and initialize *_seq tables so ADODB GenID doesn't fail with duplicate-key errors.
 * Usage: php tools/fix_sequences.php
 */

define('DP_BASE_DIR', dirname(__DIR__));
$baseDir = DP_BASE_DIR;
$baseUrl = '';
require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/config.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';

$dbprefix = dPgetConfig('dbprefix','');

echo "Scanning for sequence tables using prefix '{$dbprefix}'...\n";
$seqs = $db->GetArray("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '" . $db->qstr($dbprefix . "%_seq", false) . "' ");
if (!$seqs) { echo "No sequence tables found.\n"; exit(0); }

foreach ($seqs as $row) {
    $t = $row['table_name'];
    echo "\nProcessing sequence table: {$t}\n";

    // Ensure table has single INT 'id' column and PRIMARY KEY
    $col = $db->GetRow("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE, COLUMN_KEY FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . $db->qstr($t, false) . "' AND column_name = 'id'");
    if (!$col) {
        echo " - 'id' column missing. Attempting to add 'id INT NOT NULL PRIMARY KEY'...\n";
        try {
            $db->Execute("ALTER TABLE `{$t}` ADD COLUMN `id` INT NOT NULL PRIMARY KEY");
            echo "   Added column and PRIMARY KEY.\n";
        } catch (Exception $e) {
            echo "   Failed to add id PK: " . $e->getMessage() . "\n";
            echo "   Trying fallback: create a clean replacement table `tmp_{$t}`...\n";
            try {
                $db->Execute("CREATE TABLE IF NOT EXISTS `tmp_{$t}` ( `id` INT NOT NULL PRIMARY KEY ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->Execute("INSERT IGNORE INTO `tmp_{$t}` (`id`) SELECT DISTINCT 0 FROM `{$t}` LIMIT 1");
                $db->Execute("RENAME TABLE `{$t}` TO `old_{$t}`, `tmp_{$t}` TO `{$t}`");
                echo "   Replaced table with normalized version. Old table renamed to old_{$t}.\n";
            } catch (Exception $e2) {
                echo "   Fallback failed: " . $e2->getMessage() . "\n";
                continue;
            }
        }
    } else {
        // Ensure column is integer, not nullable and primary key
        $needsAlter = false;
        if (stripos($col['COLUMN_TYPE'], 'int') === false) $needsAlter = true;
        if ($col['IS_NULLABLE'] !== 'NO') $needsAlter = true;
        if ($col['COLUMN_KEY'] !== 'PRI') $needsAlter = true;
        if ($needsAlter) {
            echo " - 'id' column exists but not INT NOT NULL PRIMARY KEY. Altering...\n";
            try {
                $db->Execute("ALTER TABLE `{$t}` MODIFY COLUMN `id` INT NOT NULL PRIMARY KEY");
                echo "   Altered id column to INT NOT NULL PRIMARY KEY.\n";
            } catch (Exception $e) {
                echo "   Alter failed: " . $e->getMessage() . "\n";
            }
        } else {
            echo " - 'id' column looks OK.\n";
        }
    }

    // Remove duplicate rows, keep the MAX(id)
    $ids = $db->GetArray("SELECT id FROM `{$t}` ORDER BY id ASC");
    $countIds = is_array($ids) ? count($ids) : 0;
    if ($countIds > 1) {
        $max = intval(end($ids)['id']);
        echo " - Found {$countIds} rows; keeping max id={$max} and deleting others...\n";
        try {
            $db->Execute("DELETE FROM `{$t}` WHERE id <> " . intval($max));
            echo "   Duplicates removed.\n";
        } catch (Exception $e) { echo "   Failed to delete duplicates: " . $e->getMessage() . "\n"; }
    } elseif ($countIds === 1) {
        $max = intval($ids[0]['id']);
        echo " - Single row present with id={$max}.\n";
    } else {
        // No rows -> initialize from candidate table
        echo " - Table empty. Attempting to initialize from candidate tables...\n";
        $candidates = array();
        $candidates[] = preg_replace('/_seq$/', '', $t);
        $candidates[] = preg_replace('/_id_seq$/', '_id', $t);
        $candidates[] = preg_replace('/_groups_id_seq$/', '_groups', $t);
        $candidates[] = preg_replace('/_seq$/', '', preg_replace('/_id$/', '', $t));
        $initialized = false;
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if (!$cand) continue;
            $exists = intval($db->GetOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->qstr($cand, false) . "'"));
            if ($exists) {
                // Try to get max id or other suitable PK
                $maxid = $db->GetOne("SELECT COALESCE(MAX(id), COALESCE(MAX(`" . $db->qstr('id', false) . "`), 0)) FROM `{$cand}`");
                $maxid = ($maxid === null) ? 0 : intval($maxid);
                try {
                    $db->Execute("INSERT INTO `{$t}` (`id`) VALUES (" . intval($maxid) . ")");
                    echo "   Initialized {$t} to {$maxid} based on existing {$cand}\n";
                    $initialized = true;
                    break;
                } catch (Exception $e) { echo "   Failed to insert initial id from {$cand}: " . $e->getMessage() . "\n"; }
            }
        }
        if (!$initialized) {
            // insert zero as last resort
            try {
                $db->Execute("INSERT INTO `{$t}` (`id`) VALUES (0)");
                echo "   Inserted id=0 as fallback.\n";
            } catch (Exception $e) { echo "   Failed to insert fallback zero: " . $e->getMessage() . "\n"; }
        }
    }

    // Final sanity: ensure exactly one row exists
    $finalCount = intval($db->GetOne("SELECT COUNT(*) FROM `{$t}`"));
    if ($finalCount === 1) {
        $finalId = intval($db->GetOne("SELECT id FROM `{$t}` LIMIT 1"));
        echo " - Finalized: one row present with id={$finalId}.\n";
    } else {
        echo " - Warning: after fixes, table has {$finalCount} rows (expected 1). Manual inspection recommended.\n";
    }
}

echo "\nSequence normalization complete. You can re-run schema installer with --init-perms or --force if needed.\n";

?>