<?php
// tools/install_schema.php
// Idempotent CLI script to initialize the application's database schema and required admin records.
// Usage: php tools/install_schema.php [--force]

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

// Optionally regenerate permissions (simple placeholder - complex regeneration may be needed via app code)
// We'll insert a minimal permission into dotpermissions so admin can access admin app
if ($mysqli->query("SHOW TABLES LIKE '{$prefix}dotpermissions'") && $mysqli->affected_rows >= 0) {
    insertIfMissing($mysqli,
        "SELECT * FROM {$prefix}dotpermissions WHERE user_id = 1 LIMIT 1",
        "INSERT INTO {$prefix}dotpermissions (acl_id, user_id, section, axo, permission, allow, priority, enabled) VALUES (1, 1, 'app', 'admin', 'access', 1, 1, 1)",
        "minimal admin permission (dotp_dotpermissions)"
    );
}

echo "Done. You can now reload the application and proceed with the web-based installation steps or login with admin/passwd.\n";

$mysqli->close();
