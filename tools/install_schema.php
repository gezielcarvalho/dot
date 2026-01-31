<?php
// tools/install_schema.php
// Idempotent CLI script to initialize the application's database schema and required admin records.
// Usage: php tools/install_schema.php [--force] [--fix-seqs] [--init-perms]
//
// Options:
//   --force      Drop existing prefixed tables and re-import schema. Also auto-fixes missing PKs when present.
//   --fix-seqs   Normalize and initialize *_seq tables to prevent ADODB GenID duplicate-key errors.
//   --init-perms  Initialize GACL and grant full admin permissions (inlined; no external scripts invoked).

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "dotProject Schema Installer\n";

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: 3306;
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'dotproject';
$prefix = getenv('DB_PREFIX') ?: 'dotp_';

$sslCa = getenv('MYSQL_SSL_CA');
$sslMode = strtoupper(getenv('MYSQL_SSL_MODE') ?: '');

$force = in_array('--force', $argv, true);

echo "Connecting to {$host}:{$port} as {$user}...\n";

$mysqli = mysqli_init();
$flags = 0;
if (!empty($sslCa) && file_exists($sslCa)) {
    mysqli_ssl_set($mysqli, NULL, NULL, $sslCa, NULL, NULL);
    $flags |= MYSQLI_CLIENT_SSL;
    if ($sslMode === 'REQUIRED' && defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT')) {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
    if (in_array($sslMode, ['VERIFY_CA', 'VERIFY_IDENTITY']) && defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
        @mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    }
} elseif (in_array($sslMode, ['VERIFY_CA','VERIFY_IDENTITY'])) {
    echo "Warning: MYSQL_SSL_MODE requires a CA but MYSQL_SSL_CA is not set or the file is missing.\n";
}

$socket = NULL;
$connected = $flags ? @mysqli_real_connect($mysqli, $host, $user, $pass, $db, (int)$port, $socket, $flags) : @mysqli_real_connect($mysqli, $host, $user, $pass, $db, (int)$port);
if (!$connected) {
    fwrite(STDERR, "Connection failed: " . mysqli_connect_error() . "\n");
    exit(1);
}

echo "Connected.\n";

// Decide whether to drop existing tables
$doDrop = $force || in_array('--drop', $argv, true);

// If user requested drop, drop any tables found with the configured prefix regardless of current schema state
if ($doDrop) {
    echo "Dropping all tables with prefix '{$prefix}' as requested...\n";
    // Fetch all tables matching the prefix in the current database via information_schema
    $tablesToDrop = array();
    $rows = $mysqli->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name LIKE '" . $mysqli->real_escape_string($prefix) . "%'");
    if ($rows) {
        while ($r = $rows->fetch_assoc()) {
            if (isset($r['table_name']) && is_string($r['table_name']) && strlen(trim($r['table_name'])) > 0) {
                $tablesToDrop[] = $r['table_name'];
            }
        }
    }

    // Fallback: if information_schema returned nothing or is restricted, try SHOW TABLES LIKE from the connected DB
    if (count($tablesToDrop) === 0) {
        $rows2 = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($prefix) . "%'");
        if ($rows2) {
            while ($r = $rows2->fetch_row()) {
                if (isset($r[0]) && is_string($r[0]) && strlen(trim($r[0])) > 0) {
                    $tablesToDrop[] = $r[0];
                }
            }
        }
    }

    // Normalize, dedupe and validate table names (ensure they actually start with the prefix)
    $tablesToDrop = array_values(array_filter(array_unique($tablesToDrop), function($t) use ($prefix) {
        return is_string($t) && strlen($t) > 0 && preg_match('/^' . preg_quote($prefix, '/') . '/i', $t);
    }));

    if (count($tablesToDrop) > 0) {
        echo "Found " . count($tablesToDrop) . " table(s) to drop.\n";
        // Disable foreign key checks while dropping
        if (!$mysqli->query("SET FOREIGN_KEY_CHECKS=0")) {
            fwrite(STDERR, "Warning: could not disable foreign key checks: ({$mysqli->errno}) {$mysqli->error}\n");
        }
        foreach ($tablesToDrop as $t) {
            // final safety check
            if (!is_string($t) || strlen(trim($t)) === 0) continue;
            if (!preg_match('/^' . preg_quote($prefix, '/') . '/i', $t)) {
                fwrite(STDERR, "Skipping suspicious table name: {$t}\n");
                continue;
            }
            $q = "DROP TABLE IF EXISTS `" . $mysqli->real_escape_string($t) . "`";
            if (!$mysqli->query($q)) {
                fwrite(STDERR, "Failed to drop {$t}: ({$mysqli->errno}) {$mysqli->error}\n");
            } else {
                echo "Dropped {$t}\n";
            }
        }
        if (!$mysqli->query("SET FOREIGN_KEY_CHECKS=1")) {
            fwrite(STDERR, "Warning: could not restore foreign key checks: ({$mysqli->errno}) {$mysqli->error}\n");
        }
    } else {
        echo "No tables found with prefix '{$prefix}' to drop.\n";
    }
} else {
    // Check if required table exists
    $testTable = $prefix . 'config';
    $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($testTable) . "'");
    if ($res && $res->num_rows > 0) {
        echo "Database already has baseline tables (found {$testTable}).\n";
        echo "Run with --force or --drop to re-import schema (may fail or drop existing data). Aborting.\n";
        exit(0);
    }
}

// Import schema
$schemaFile = __DIR__ . '/../db/dotproject.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "Schema file not found: {$schemaFile}\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);
$sql = str_replace('%dbprefix%', $prefix, $sql);

// Temporarily relax sql_mode (some schema statements use zero-dates / non-strict defaults)
$origSqlMode = null;
if ($r = $mysqli->query("SELECT @@SESSION.sql_mode AS m")) {
    $row = $r->fetch_assoc();
    $origSqlMode = $row['m'];
    echo "Current session sql_mode: " . ($origSqlMode === null ? '(null)' : $origSqlMode) . "\n";
}
if (!($mysqli->query("SET SESSION sql_mode = ''"))) {
    fwrite(STDERR, "Warning: could not set session sql_mode to empty: ({$mysqli->errno}) {$mysqli->error}\n");
} else {
    echo "Session sql_mode cleared for import.\n";
}

// Temporarily attempt to disable sql_require_primary_key for session if present (helps import older schemas)
$origRequirePK = null;
$requirePKDisabled = false;
if ($r2 = $mysqli->query("SELECT @@SESSION.sql_require_primary_key AS v")) {
    $row2 = $r2->fetch_assoc();
    $origRequirePK = isset($row2['v']) ? $row2['v'] : null;
    echo "Current session sql_require_primary_key: " . var_export($origRequirePK, true) . "\n";
    // Try to set to 0 for import
    if ($mysqli->query("SET SESSION sql_require_primary_key = 0")) {
        $requirePKDisabled = true;
        echo "Session sql_require_primary_key disabled for import.\n";
    } else {
        fwrite(STDERR, "Warning: could not set session sql_require_primary_key to 0: ({$mysqli->errno}) {$mysqli->error}\n");
        // Leave $requirePKDisabled false
    }
} else {
    // Variable might not exist on this server; continue
    echo "sql_require_primary_key variable not present or not accessible in session; continuing.\n";
}

try {
    // Use multi_query to execute the whole script; handle results
    if ($mysqli->multi_query($sql)) {
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
        if ($mysqli->errno) {
            throw new Exception("Error executing schema SQL: ({$mysqli->errno}) {$mysqli->error}");
        }
        echo "Schema import completed.\n";

// Ensure ADODB/phpGACL sequence tables exist and are created with PRIMARY KEY
$seqs = array(
    $prefix . 'gacl_acl_seq',
    $prefix . 'gacl_aco_sections_seq',
    $prefix . 'gacl_aco_seq',
    $prefix . 'gacl_aro_seq',
    $prefix . 'gacl_aro_sections_seq',
    $prefix . 'gacl_axo_seq',
    $prefix . 'gacl_axo_sections_seq',
    $prefix . 'gacl_aro_groups_id_seq',
    $prefix . 'gacl_axo_groups_id_seq',
    $prefix . 'gacl_role_groups_id_seq',
    $prefix . 'gacl_mod_groups_id_seq',
);
foreach ($seqs as $s) {
    echo "Ensuring sequence table: {$s}\n";
    $createSql = "CREATE TABLE IF NOT EXISTS `{$s}` ( `id` INT NOT NULL PRIMARY KEY ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$mysqli->query($createSql)) {
        fwrite(STDERR, "Failed to create sequence table {$s}: ({$mysqli->errno}) {$mysqli->error}\n");
        continue;
    }
    // insert a row if empty
    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `{$s}`");
    $cnt = ($res && ($r = $res->fetch_assoc())) ? intval($r['c']) : 0;
    if ($cnt == 0) {
        // Attempt to initialize sequence from candidate target tables
        $candidates = array();
        $candidates[] = preg_replace('/_seq$/', '', $s);
        $candidates[] = preg_replace('/_id_seq$/', '_id', $s);
        $candidates[] = preg_replace('/_groups_id_seq$/', '_groups', $s);
        $candidates[] = preg_replace('/_seq$/', '', preg_replace('/_id$/', '', $s));
        $initialized = false;
        foreach ($candidates as $cand) {
            if (!$cand) continue;
            // normalize candidate
            $cand = $mysqli->real_escape_string($cand);
            // Check table existence safely via information_schema
            $exists_q = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $cand . "'");
            if ($exists_q && ($er = $exists_q->fetch_assoc()) && intval($er['c']) > 0) {
                $max = $mysqli->query("SELECT COALESCE(MAX(id),0) AS m FROM `{$cand}`");
                $m = ($max && ($rm = $max->fetch_assoc())) ? intval($rm['m']) : 0;
                if ($mysqli->query("INSERT INTO `{$s}` (`id`) VALUES (" . intval($m) . ")")) {
                    echo "Initialized sequence {$s} to {$m} based on existing {$cand}\n";
                    $initialized = true;
                }
                break;
            }
        }
        if (!$initialized) {
            // Insert zero if no target found
            if ($mysqli->query("INSERT INTO `{$s}` (`id`) VALUES (0)")) {
                echo "Created sequence {$s} with id=0 (no target table found)\n";
            } else {
                fwrite(STDERR, "Failed to insert initial row into {$s}: ({$mysqli->errno}) {$mysqli->error}\n");
            }
        }
    } else {
        echo "Sequence table {$s} exists with {$cnt} row(s); leaving as-is.\n";
    }
}

// Normalize and repair sequence tables to avoid ADODB GenID duplicate errors (inlined)
if ($force || in_array('--fix-seqs', $argv, true)) {
    echo "\nNormalizing sequence tables inlined...\n";
    $seqCandidates = array(
        $prefix . 'gacl_acl_seq',
        $prefix . 'gacl_aco_sections_seq',
        $prefix . 'gacl_aco_seq',
        $prefix . 'gacl_aro_seq',
        $prefix . 'gacl_aro_sections_seq',
        $prefix . 'gacl_axo_seq',
        $prefix . 'gacl_axo_sections_seq',
        $prefix . 'gacl_aro_groups_id_seq',
        $prefix . 'gacl_axo_groups_id_seq',
        $prefix . 'gacl_role_groups_id_seq',
        $prefix . 'gacl_mod_groups_id_seq',
    );
    foreach ($seqCandidates as $s) {
        echo "Processing sequence table: {$s}\n";
        // Ensure table exists
        $r = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $mysqli->real_escape_string($s) . "'");
        $row = ($r && ($rr = $r->fetch_assoc())) ? intval($rr['c']) : 0;
        if ($row === 0) {
            echo " - Table {$s} missing; attempting to create with PRIMARY KEY and seed=0\n";
            if (!$mysqli->query("CREATE TABLE IF NOT EXISTS `" . $mysqli->real_escape_string($s) . "` (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
                echo "   Failed to create {$s}: ({$mysqli->errno}) {$mysqli->error}\n";
                continue;
            }
            $mysqli->query("INSERT IGNORE INTO `" . $mysqli->real_escape_string($s) . "` (`id`) VALUES (0)");
        }
        // Ensure only one row; keep the max id
        $res = $mysqli->query("SELECT id FROM `" . $mysqli->real_escape_string($s) . "` ORDER BY id ASC");
        if (!$res) { echo " - Failed to read rows for {$s}: ({$mysqli->errno}) {$mysqli->error}\n"; continue; }
        $ids = array();
        while ($rr = $res->fetch_assoc()) $ids[] = intval($rr['id']);
        $cnt = count($ids);
        if ($cnt > 1) {
            $max = max($ids);
            echo " - Found {$cnt} rows; keeping max id={$max} and deleting others...\n";
            if (!$mysqli->query("DELETE FROM `" . $mysqli->real_escape_string($s) . "` WHERE id <> " . intval($max))) {
                echo "   Failed to delete duplicates: ({$mysqli->errno}) {$mysqli->error}\n";
            }
        } elseif ($cnt === 0) {
            // Try to seed from candidate target tables
            echo " - Empty sequence table; attempting to initialize from candidate target tables...\n";
            $cands = array();
            $cands[] = preg_replace('/_seq$/', '', $s);
            $cands[] = preg_replace('/_id_seq$/', '_id', $s);
            $cands[] = preg_replace('/_groups_id_seq$/', '_groups', $s);
            $cands[] = preg_replace('/_seq$/', '', preg_replace('/_id$/', '', $s));
            $initialized = false;
            foreach ($cands as $cand) {
                $cand = $mysqli->real_escape_string($cand);
                $exists_q = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $cand . "'");
                $exists = ($exists_q && ($er = $exists_q->fetch_assoc())) ? intval($er['c']) : 0;
                if ($exists) {
                    $maxRes = $mysqli->query("SELECT COALESCE(MAX(id),0) AS m FROM `" . $cand . "`");
                    $m = ($maxRes && ($rm = $maxRes->fetch_assoc())) ? intval($rm['m']) : 0;
                    if ($mysqli->query("INSERT INTO `" . $mysqli->real_escape_string($s) . "` (`id`) VALUES (" . intval($m) . ")")) {
                        echo "   Initialized {$s} to {$m} based on existing {$cand}\n";
                        $initialized = true;
                        break;
                    }
                }
            }
            if (!$initialized) {
                $mysqli->query("INSERT IGNORE INTO `" . $mysqli->real_escape_string($s) . "` (`id`) VALUES (0)");
                echo "   Inserted id=0 as fallback for {$s}\n";
            }
        } else {
            echo " - One row present (id={$ids[0]})\n";
        }
    }
}

// Permissions initialization inlined: ensure admin has full allow entries
if ($force || in_array('--init-perms', $argv, true)) {
    echo "\nEnsuring admin permissions are present (inlined)...\n";
    // Check gacl tables exist and have data
    $gaclChecks = array('gacl_aco','gacl_axo','gacl_aco_map','gacl_axo_map');
    $missing = array();
    foreach ($gaclChecks as $t) {
        $r = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $mysqli->real_escape_string($prefix . $t) . "'");
        $c = ($r && ($rr = $r->fetch_assoc())) ? intval($rr['c']) : 0;
        if ($c === 0) $missing[] = $t;
    }
    if (count($missing)) {
        echo "Warning: missing GACL tables: " . implode(', ', $missing) . " - ensure schema includes GACL.\n";
    } else {
        // If AXO table is empty but modules exist, populate gacl_axo from modules so permissions can be derived
        $axoCount = intval($mysqli->query("SELECT COUNT(*) AS c FROM `" . $mysqli->real_escape_string($prefix . "gacl_axo") . "`")->fetch_assoc()['c']);
        if ($axoCount === 0) {
            $modExists = intval($mysqli->query("SELECT COUNT(*) AS c FROM `" . $mysqli->real_escape_string($prefix . "modules") . "`")->fetch_assoc()['c']);
            if ($modExists > 0) {
                echo " - gacl_axo is empty; populating from modules table...\n";
                // Assign incremental IDs starting from current max(id)
                $mysqli->query("SET @rownum = COALESCE((SELECT MAX(id) FROM `" . $mysqli->real_escape_string($prefix . "gacl_axo") . "`), 0)");
                $populateSql = "INSERT INTO `" . $mysqli->real_escape_string($prefix . "gacl_axo") . "` (id, section_value, value, order_value, name, hidden)\nSELECT (@rownum := @rownum + 1) AS id, 'app', mod_directory, COALESCE(mod_ui_order, 0), mod_ui_name, 0 FROM `" . $mysqli->real_escape_string($prefix . "modules") . "` ORDER BY COALESCE(mod_ui_order, 0)";
                if ($mysqli->query($populateSql)) {
                    echo "   Populated gacl_axo from modules.\n";
                } else {
                    echo "   Failed to populate gacl_axo: ({$mysqli->errno}) {$mysqli->error}\n";
                }
            }
        }

        // Insert allow entries for admin user 1 across all axo x aco combinations (idempotent)
        $insert = "INSERT INTO `" . $mysqli->real_escape_string($prefix . 'dotpermissions') . "` (acl_id,user_id,section,axo,permission,allow,priority,enabled)\nSELECT 0, '1', axo.section_value, axo.value, aco.value, 1, 1, 1\nFROM `" . $mysqli->real_escape_string($prefix . 'gacl_axo') . "` axo\nCROSS JOIN `" . $mysqli->real_escape_string($prefix . 'gacl_aco') . "` aco\nWHERE NOT EXISTS (\n    SELECT 1 FROM `" . $mysqli->real_escape_string($prefix . 'dotpermissions') . "` dp\n    WHERE dp.user_id = '1' AND dp.section = axo.section_value\n      AND dp.axo = axo.value AND dp.permission = aco.value\n)\nGROUP BY axo.section_value, axo.value, aco.value";
        if ($mysqli->query($insert)) {
            $countRes = $mysqli->query("SELECT COUNT(*) AS c FROM `" . $mysqli->real_escape_string($prefix . 'dotpermissions') . "` WHERE user_id = '1'");
            $count = ($countRes && ($r2 = $countRes->fetch_assoc())) ? intval($r2['c']) : 0;
            echo "Inserted allow entries for user 1 (total now: {$count})\n";
        } else {
            echo "Failed to insert admin allow entries: ({$mysqli->errno}) {$mysqli->error}\n";
        }
    }
}

// Report any dot-prefixed tables that lack a PRIMARY KEY — helpful to detect import issues
echo "\nChecking for tables without PRIMARY KEY (this may indicate incomplete import)...\n";
$missingPK = array();
$tbls = $mysqli->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name LIKE '" . $mysqli->real_escape_string($prefix) . "%'");
if ($tbls) {
    while ($tr = $tbls->fetch_row()) {
        if (!isset($tr[0]) || $tr[0] === null) continue;
        $t = $tr[0];
        $t_esc = $mysqli->real_escape_string($t);
        $q = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.table_constraints WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $t_esc . "' AND constraint_type = 'PRIMARY KEY'");
        $c = ($q && ($r = $q->fetch_assoc())) ? intval($r['c']) : 0;
        if ($c === 0) {
            $missingPK[] = $t;
        }
    }
}
if (count($missingPK)) {
    echo "Tables without PRIMARY KEY (count: " . count($missingPK) . "):\n";
    foreach ($missingPK as $m) echo " - {$m}\n";
    echo "\nIf tables are listed here, consider adding primary keys or re-running the installer in an environment where 'sql_require_primary_key' can be disabled during import.\n";

    // Optionally fix missing primary keys when --fix-pks passed on command line
    if ($force || in_array('--fix-pks', $argv, true)) {
        echo "\nAutomatic PK fix mode enabled (--force or --fix-pks): attempting to add PRIMARY KEYs to missing tables.\n";
        // Try to disable session sql_require_primary_key (best-effort)
        $origRequirePK = null;
        $r = $mysqli->query("SELECT @@SESSION.sql_require_primary_key AS v");
        if ($r && ($row = $r->fetch_assoc())) {
            $origRequirePK = $row['v'];
            echo "Current session sql_require_primary_key: " . var_export($origRequirePK, true) . "\n";
            if (!$mysqli->query("SET SESSION sql_require_primary_key = 0")) {
                fwrite(STDERR, "Warning: could not disable sql_require_primary_key: ({$mysqli->errno}) {$mysqli->error}\n");
            } else {
                echo "Session sql_require_primary_key disabled for fix.\n";
            }
        }

        foreach ($missingPK as $t) {
            echo "\nProcessing table: {$t}\n";
            // Double-check exists
            $check = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $mysqli->real_escape_string($t) . "'");
            $exists = ($check && ($cr = $check->fetch_assoc())) ? intval($cr['c']) : 0;
            if (!$exists) { echo " - Table does not exist, skipping.\n"; continue; }
            $hasPK = intval($mysqli->query("SELECT COUNT(*) AS c FROM information_schema.table_constraints WHERE table_schema = '" . $mysqli->real_escape_string($db) . "' AND table_name = '" . $mysqli->real_escape_string($t) . "' AND constraint_type = 'PRIMARY KEY'")->fetch_assoc()['c']);
            if ($hasPK) { echo " - Table already has a PRIMARY KEY; skipping.\n"; continue; }

            // Try direct add
            if ($mysqli->query("ALTER TABLE `" . $mysqli->real_escape_string($t) . "` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST")) {
                echo " - Added 'id' PRIMARY KEY to {$t}.\n";
            } else {
                echo " - Direct add failed: ({$mysqli->errno}) {$mysqli->error}\n";
                // Fallback: add nullable id, populate sequential values, then make it PK
                if ($mysqli->query("ALTER TABLE `" . $mysqli->real_escape_string($t) . "` ADD COLUMN `id` INT NULL")) {
                    // populate sequential ids
                    $mysqli->query("SET @rownum = 0");
                    if ($mysqli->query("UPDATE `" . $mysqli->real_escape_string($t) . "` SET id = (@rownum := @rownum + 1)")) {
                        if ($mysqli->query("ALTER TABLE `" . $mysqli->real_escape_string($t) . "` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY")) {
                            echo "   Fallback succeeded for {$t}.\n";
                        } else {
                            echo "   Failed to set id as AUTO_INCREMENT PRIMARY KEY for {$t}: ({$mysqli->errno}) {$mysqli->error}\n";
                        }
                    } else {
                        echo "   Failed to populate id values for {$t}: ({$mysqli->errno}) {$mysqli->error}\n";
                    }
                } else {
                    echo "   Fallback add column failed for {$t}: ({$mysqli->errno}) {$mysqli->error}\n";
                }
            }
        }

        // Restore session sql_require_primary_key
        if ($origRequirePK !== null) {
            $val = ((int)$origRequirePK) ? 1 : 0;
            if ($mysqli->query("SET SESSION sql_require_primary_key = " . $val)) {
                echo "\nSession sql_require_primary_key restored to: " . var_export($origRequirePK, true) . "\n";
            } else {
                fwrite(STDERR, "Warning: failed to restore session sql_require_primary_key: ({$mysqli->errno}) {$mysqli->error}\n");
            }
        }

        echo "\nPK fix completed. You may re-run this script or continue with installation.\n";
    } else {
        echo "Run this script with --fix-pks or --force to attempt to add missing PRIMARY KEYs automatically.\n";
    }
} else {
    echo "All tables have PRIMARY KEYs.\n";
}

    } else {
        throw new Exception("Failed to execute schema import: ({$mysqli->errno}) {$mysqli->error}");
    }
} catch (Exception $e) {
    // Attempt to restore original sql_mode before exiting
    if ($origSqlMode !== null) {
        $mysqli->query("SET SESSION sql_mode = '" . $mysqli->real_escape_string($origSqlMode) . "'");
    }
    // Attempt to restore sql_require_primary_key if we changed it
    if (isset($origRequirePK) && $requirePKDisabled) {
        $valToSet = ((int)$origRequirePK) ? 1 : 0;
        $mysqli->query("SET SESSION sql_require_primary_key = " . $valToSet);
    }
    fwrite(STDERR, "Schema import failed: " . $e->getMessage() . "\n");
    // If the error mentions sql_require_primary_key, add actionable instructions
    $msg = $e->getMessage();
    if (stripos($msg, 'sql_require_primary_key') !== false) {
        fwrite(STDERR, "Hint: The server enforces 'sql_require_primary_key'. You can either disable it for the session or the server, or adjust the schema to add primary keys to the affected tables.\n");
        fwrite(STDERR, "If you're using Aiven: open the Aiven Console -> Service -> Configuration, and set 'sql_require_primary_key' to 'false' (or 0) and restart the service.\n");
    }
    exit(1);
}

// Restore original sql_mode if we changed it
if ($origSqlMode !== null) {
    if ($mysqli->query("SET SESSION sql_mode = '" . $mysqli->real_escape_string($origSqlMode) . "'")) {
        echo "Session sql_mode restored to: {$origSqlMode}\n";
    } else {
        fwrite(STDERR, "Warning: failed to restore session sql_mode: ({$mysqli->errno}) {$mysqli->error}\n");
    }
} else {
    echo "No original session sql_mode to restore.\n";
}

// Restore sql_require_primary_key if we disabled it
if (isset($origRequirePK) && $requirePKDisabled) {
    $valToSet = ((int)$origRequirePK) ? 1 : 0;
    if ($mysqli->query("SET SESSION sql_require_primary_key = " . $valToSet)) {
        echo "Session sql_require_primary_key restored to: " . var_export($origRequirePK, true) . "\n";
    } else {
        fwrite(STDERR, "Warning: failed to restore session sql_require_primary_key: ({$mysqli->errno}) {$mysqli->error}\n");
    }
} else {
    echo "No session sql_require_primary_key changes to restore.\n";
}

// Insert required records if missing
function insertIfMissing($mysqli, $queryCheck, $queryInsert, $desc) {
    $check = $mysqli->query($queryCheck);
    if ($check && $check->num_rows > 0) {
        echo "{$desc} already present; skipping.\n";
        return;
    }
    if ($mysqli->query($queryInsert)) {
        echo "Inserted: {$desc}\n";
    } else {
        echo "Failed to insert {$desc}: ({$mysqli->errno}) {$mysqli->error}\n";
    }
}

// Users and contacts
insertIfMissing($mysqli,
    "SELECT user_id FROM {$prefix}users WHERE user_id = 1",
    "INSERT INTO {$prefix}users (user_id, user_contact, user_username, user_password, user_type) VALUES (1, 1, 'admin', '76a2173be639254e72ffa4d6df1030a', 1)",
    "admin user (dotp_users)"
);

insertIfMissing($mysqli,
    "SELECT contact_id FROM {$prefix}contacts WHERE contact_id = 1",
    "INSERT INTO {$prefix}contacts (contact_id, contact_first_name, contact_last_name, contact_email) VALUES (1, 'Admin', 'User', 'admin@example.com')",
    "admin contact (dotp_contacts)"
);

// gacl entries
insertIfMissing($mysqli,
    "SELECT id FROM {$prefix}gacl_aro WHERE id = 1",
    "INSERT INTO {$prefix}gacl_aro (id, section_value, value, order_value, name, hidden) VALUES (1, 'user', '1', 1, 'Administrator', 0)",
    "gacl aro (dotp_gacl_aro)"
);
insertIfMissing($mysqli,
    "SELECT id FROM {$prefix}gacl_aro_groups WHERE id = 1",
    "INSERT INTO {$prefix}gacl_aro_groups (id, parent_id, lft, rgt, name, value) VALUES (1, 0, 1, 2, 'Administrator', 'admin')",
    "gacl aro groups (dotp_gacl_aro_groups)"
);
insertIfMissing($mysqli,
    "SELECT group_id FROM {$prefix}gacl_groups_aro_map WHERE group_id = 1 AND aro_id = 1",
    "INSERT INTO {$prefix}gacl_groups_aro_map (group_id, aro_id) VALUES (1, 1)",
    "gacl groups <-> aro mapping (dotp_gacl_groups_aro_map)"
);

// Optionally regenerate permissions (complex regeneration required to grant full admin privileges)
// Instead of a minimal permission, ensure the admin user (user_id = 1) receives allow entries for all current axo/aco combinations.
if ($mysqli->query("SHOW TABLES LIKE '{$prefix}dotpermissions'") && $mysqli->affected_rows >= 0) {
    // Remove explicit denies for the admin user to avoid precedence issues
    if ($mysqli->query("DELETE FROM {$prefix}dotpermissions WHERE user_id = '1' AND allow = 0")) {
        echo "Removed explicit denies for user 1\n";
    } else {
        echo "Warning: failed to remove denies for user 1: ({$mysqli->errno}) {$mysqli->error}\n";
    }

    // Insert allow entries for all known axo/permission combos based on gacl mappings
    $insertSql = "INSERT INTO {$prefix}dotpermissions (acl_id,user_id,section,axo,permission,allow,priority,enabled)
SELECT 0, '1', axo.section_value, axo.value, aco.value, 1, 1, 1
FROM {$prefix}gacl_aco_map aco_m
JOIN {$prefix}gacl_aco aco ON aco_m.value = aco.value
JOIN {$prefix}gacl_axo_map axo_m ON aco_m.acl_id = axo_m.acl_id
JOIN {$prefix}gacl_axo axo ON axo_m.value = axo.value
GROUP BY axo.section_value, axo.value, aco.value
ON DUPLICATE KEY UPDATE allow = VALUES(allow), enabled = VALUES(enabled), priority = VALUES(priority)";

    if ($mysqli->query($insertSql)) {
        echo "Inserted allow entries for user 1\n";
    } else {
        echo "Error inserting allow entries: ({$mysqli->errno}) {$mysqli->error}\n";
        echo "Attempting fallback insert using NOT EXISTS clause...\n";
        $fallbackSql = "INSERT INTO {$prefix}dotpermissions (acl_id,user_id,section,axo,permission,allow,priority,enabled)
SELECT 0, '1', axo.section_value, axo.value, aco.value, 1, 1, 1
FROM {$prefix}gacl_aco_map aco_m
JOIN {$prefix}gacl_aco aco ON aco_m.value = aco.value
JOIN {$prefix}gacl_axo_map axo_m ON aco_m.acl_id = axo_m.acl_id
JOIN {$prefix}gacl_axo axo ON axo_m.value = axo.value
WHERE NOT EXISTS (
    SELECT 1 FROM {$prefix}dotpermissions dp
    WHERE dp.user_id = '1' AND dp.section = axo.section_value
      AND dp.axo = axo.value AND dp.permission = aco.value
)
GROUP BY axo.section_value, axo.value, aco.value";
        if ($mysqli->query($fallbackSql)) {
            echo "Fallback insert completed for user 1\n";
        } else {
            echo "Fallback also failed: ({$mysqli->errno}) {$mysqli->error}\n";
        }
    }

    $countRes = $mysqli->query("SELECT COUNT(*) AS c FROM {$prefix}dotpermissions WHERE user_id = '1'");
    $count = ($countRes && ($r = $countRes->fetch_assoc())) ? intval($r['c']) : 0;
    echo "Total permission rows for user 1: " . intval($count) . "\n";
}

echo "Done. You can now reload the application and proceed with the web-based installation steps or login with admin/passwd.\n";

$mysqli->close();
