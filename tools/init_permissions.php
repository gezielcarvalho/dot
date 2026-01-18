<?php
/**
 * init_permissions.php
 *
 * Ensure the basic GACL / permissions structure exists and that the specified
 * admin user is assigned to the Administrator role. Intended for freshly
 * created databases where permission-related tables may be empty.
 *
 * Usage: php tools/init_permissions.php [admin_user_id]
 */

define('DP_BASE_DIR', dirname(__DIR__));

// Minimal bootstrap for CLI
$baseDir = DP_BASE_DIR;
$baseUrl = '';
require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/config.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';
require_once DP_BASE_DIR . '/classes/permissions.class.php';

$adminUser = isset($argv[1]) && is_numeric($argv[1]) ? intval($argv[1]) : 1;
$dbprefix = dPgetConfig('dbprefix','');

echo "Initializing permissions (ensuring GACL entries and admin membership).\n";

try {
    $perms = new dPacl();
} catch (Exception $e) {
    echo "Failed to initialize dPacl: " . $e->getMessage() . "\n";
    exit(1);
}

// Utility to check table counts
function tcount($table) {
    global $db, $dbprefix;
    return intval($db->GetOne("SELECT COUNT(*) FROM {$dbprefix}{$table}"));
}

$counts = [
    'gacl_aro' => tcount('gacl_aro'),
    'gacl_aro_groups' => tcount('gacl_aro_groups'),
    'gacl_axo' => tcount('gacl_axo'),
    'gacl_axo_groups' => tcount('gacl_axo_groups'),
    'gacl_aco' => tcount('gacl_aco'),
    'gacl_acl' => tcount('gacl_acl')
];

echo "Current counts: ";
foreach ($counts as $k => $v) { echo "{$k}={$v} "; }
echo "\n";

// If axo/aco entries are missing, create the standard set (based on upgrade_permissions.php)
$need_setup = ($counts['gacl_axo'] == 0 || $counts['gacl_aco'] == 0 || $counts['gacl_aro_groups'] == 0);

if ($need_setup) {
    echo "GACL appears incomplete, creating default sections, groups, objects and ACLs...\n";

    // Ensure ADODB sequence tables exist for phpGACL (pre-create to avoid GenID race/exception issues)
    // phpGACL expects sequences that include the 'gacl_' namespace
    $seqs = array(
        $dbprefix . 'gacl_acl_seq',
        $dbprefix . 'gacl_aco_sections_seq',
        $dbprefix . 'gacl_aco_seq',
        $dbprefix . 'gacl_aro_seq',
        $dbprefix . 'gacl_aro_sections_seq',
        $dbprefix . 'gacl_axo_seq',
        $dbprefix . 'gacl_axo_sections_seq',
        // sequences used when creating groups
        $dbprefix . 'gacl_aro_groups_id_seq',
        $dbprefix . 'gacl_axo_groups_id_seq',
        // legacy/alternative names
        $dbprefix . 'gacl_role_groups_id_seq',
        $dbprefix . 'gacl_mod_groups_id_seq',
    );
    foreach ($seqs as $s) {
        // Use ADODB helper to create sequence if not exists
        echo "Ensuring sequence table: {$s}\n";
        try {
            if (!@$db->CreateSequence($s, 1)) {
                throw new Exception('CreateSequence returned false');
            }
        } catch (Exception $e) {
            // Fallback: driver-specific creation may be blocked by sql_require_primary_key.
            echo "CreateSequence failed: " . $e->getMessage() . " — attempting robust fallback.\n";
            // Try driver fallback SQL if available
            $created = false;
            if (!empty($db->_genSeqSQL) && !empty($db->_genSeq2SQL) && !empty($db->_genSeqCountSQL)) {
                // First attempt: driver's default create (may fail on strict servers)
                try {
                    $db->Execute(sprintf($db->_genSeqSQL, $s));
                    $cnt = intval($db->GetOne(sprintf($db->_genSeqCountSQL, $s)));
                    if (!$cnt) {
                        $db->Execute(sprintf($db->_genSeq2SQL, $s, 0));
                    }
                    $cnt = intval($db->GetOne(sprintf($db->_genSeqCountSQL, $s)));
                    $created = ($cnt > 0);
                } catch (Exception $e3) {
                    echo "Driver sequence creation failed: " . $e3->getMessage() . "\n";
                    $created = false;
                }
            }
            // If still not created, create table explicitly with PRIMARY KEY to satisfy sql_require_primary_key
            if (!$created) {
                echo "Attempting to create sequence table with PRIMARY KEY: {$s}\n";
                $sql = "CREATE TABLE IF NOT EXISTS `{$s}` ( `id` INT NOT NULL PRIMARY KEY ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                try {
                    $db->Execute($sql);
                    // insert a row if empty
                    $cnt = intval(@$db->GetOne("SELECT COUNT(*) FROM `{$s}`"));
                    if (!$cnt) {
                        $db->Execute("INSERT INTO `{$s}` (`id`) VALUES (0)");
                    }
                    // Ensure sequence reflects current max id of related table to avoid duplicates
                    try {
                        // Try several candidate table names for initializing the sequence
                        $candidates = array();
                        $candidates[] = preg_replace('/_seq$/', '', $s);
                        // If name ends with _id_seq remove _id
                        $candidates[] = preg_replace('/_id_seq$/', '_id', $s);
                        // Try replacing _groups_id_seq with _groups
                        $candidates[] = preg_replace('/_groups_id_seq$/', '_groups', $s);
                        // Also try removing trailing _id
                        $candidates[] = preg_replace('/_seq$/', '', preg_replace('/_id$/', '', $s));

                        $found = false;
                        foreach ($candidates as $cand) {
                            // normalize
                            $cand = trim($cand);
                            if (!$cand) continue;
                            // Check existence via information_schema to avoid SQL errors if table missing
                            $exists = intval($db->GetOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $db->qstr($cand, false) . "'"));
                            if ($exists) {
                                $cnt = intval($db->GetOne("SELECT COUNT(*) FROM `{$cand}`"));
                                if ($cnt) {
                                    $maxid = intval($db->GetOne("SELECT COALESCE(MAX(id),0) FROM `{$cand}`"));
                                    $db->Execute("UPDATE `{$s}` SET id = " . intval($maxid));
                                    echo "Initialized sequence {$s} to {$maxid} based on existing {$cand}\n";
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            echo "Warning: could not find a target table to initialize sequence {$s}; leaving id=0\n";
                        }
                    } catch (Exception $e3) {
                        echo "Warning: could not initialize sequence {$s}: " . $e3->getMessage() . "\n";
                    }
                } catch (Exception $e2) {
                    echo "Failed to create sequence table {$s}: " . $e2->getMessage() . "\n";
                }
            }
        }
    }

    // Sections (idempotent checks)
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco_sections WHERE value = 'system'")) {
        $perms->add_object_section('System', 'system', 1, 0, 'aco');
    } else { echo "Section aco 'system' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco_sections WHERE value = 'application'")) {
        $perms->add_object_section('Application', 'application', 2, 0, 'aco');
    } else { echo "Section aco 'application' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aro_sections WHERE value = 'user'")) {
        $perms->add_object_section('Users', 'user', 1, 0, 'aro');
    } else { echo "Section aro 'user' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_axo_sections WHERE value = 'sys'")) {
        $perms->add_object_section('System', 'sys', 1, 0, 'axo');
    } else { echo "Section axo 'sys' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_axo_sections WHERE value = 'app'")) {
        $perms->add_object_section('Application', 'app', 2, 0, 'axo');
    } else { echo "Section axo 'app' exists, skipping.\n"; }

    // ACOs (idempotent)
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'login'")) {
        $perms->add_object('system', 'Login', 'login', 1, 0, 'aco');
    } else { echo "ACO 'login' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'access'")) {
        $perms->add_object('application', 'Access', 'access', 1, 0, 'aco');
    } else { echo "ACO 'access' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'view'")) {
        $perms->add_object('application', 'View', 'view', 2, 0, 'aco');
    } else { echo "ACO 'view' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'add'")) {
        $perms->add_object('application', 'Add', 'add', 3, 0, 'aco');
    } else { echo "ACO 'add' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'edit'")) {
        $perms->add_object('application', 'Edit', 'edit', 4, 0, 'aco');
    } else { echo "ACO 'edit' exists, skipping.\n"; }
    if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_aco WHERE value = 'delete'")) {
        $perms->add_object('application', 'Delete', 'delete', 5, 0, 'aco');
    } else { echo "ACO 'delete' exists, skipping.\n"; }

    // Groups (idempotent)
    $role = $perms->get_group_id('role', null, 'aro') ?: $perms->add_group('role', 'Roles', 0, 'aro');
    $admin_role = $perms->get_group_id('admin', null, 'aro') ?: $perms->add_group('admin', 'Administrator', $role, 'aro');
    $anon_role = $perms->get_group_id('anon', null, 'aro') ?: $perms->add_group('anon', 'Anonymous', $role, 'aro');
    $guest_role = $perms->get_group_id('guest', null, 'aro') ?: $perms->add_group('guest', 'Guest', $role, 'aro');
    $worker_role = $perms->get_group_id('normal', null, 'aro') ?: $perms->add_group('normal', 'Project worker', $role, 'aro');

    $mod = $perms->get_group_id('mod', null, 'axo') ?: $perms->add_group('mod', 'Modules', 0, 'axo');
    $all_mods = $perms->get_group_id('all', null, 'axo') ?: $perms->add_group('all', 'All Modules', $mod, 'axo');
    $admin_mods = $perms->get_group_id('admin', null, 'axo') ?: $perms->add_group('admin', 'Admin Modules', $mod, 'axo');
    $non_admin_mods = $perms->get_group_id('non_admin', null, 'axo') ?: $perms->add_group('non_admin', 'Non-Admin Modules', $mod, 'axo');

    // AXOs (apps) - idempotent
    $axo_items = array(
        array('sys','ACL Administration','acl'),
        array('app','User Administration','admin'),
        array('app','Calendar','calendar'),
        array('app','Events','events'),
        array('app','Companies','companies'),
        array('app','Contacts','contacts'),
        array('app','Departments','departments'),
        array('app','Files','files'),
        array('app','File Folders','file_folders'),
        array('app','Forums','forums'),
        array('app','Help','help'),
        array('app','Projects','projects'),
        array('app','System Administration','system'),
        array('app','Tasks','tasks'),
        array('app','Task Logs','task_log'),
        array('app','Tickets','ticketsmith'),
        array('app','Public','public'),
        array('app','Roles Administration','roles'),
        array('app','User Table','users'),
    );
    foreach ($axo_items as $i) {
        list($section, $name, $value) = $i;
        if (!$db->GetOne("SELECT COUNT(*) FROM {$dbprefix}gacl_axo WHERE value = '" . addslashes($value) . "'")) {
            try { $perms->add_object($section, $name, $value, 1, 0, 'axo'); } catch (Exception $e) { echo "Warning: add_object failed for axo {$value}: " . $e->getMessage() . "\n"; }
        } else { echo "AXO '{$value}' exists, skipping.\n"; }
    }

    // helper to add group objects safely (idempotent)
    function safe_add_group_object($perms, $group_id, $section, $item, $type = 'axo') {
        global $db, $dbprefix;
        $map_table = ($type == 'axo') ? $dbprefix . 'gacl_groups_axo_map' : $dbprefix . 'gacl_groups_aro_map';
        $obj_table = ($type == 'axo') ? $dbprefix . 'gacl_axo' : $dbprefix . 'gacl_aro';
        $obj_id = $db->GetOne("SELECT id FROM {$obj_table} WHERE value = '" . addslashes($item) . "' LIMIT 1");
        if (!$obj_id) {
            try { $perms->add_group_object($group_id, $section, $item, $type); return; } catch (Exception $e) { echo "Warning: failed to add group object {$item}: " . $e->getMessage() . "\n"; return; }
        }
        $exists = $db->GetOne("SELECT COUNT(*) FROM {$map_table} WHERE group_id = '" . intval($group_id) . "' AND " . (($type=='axo') ? 'axo_id' : 'aro_id') . " = '" . intval($obj_id) . "'");
        if ($exists) { echo "Mapping for group {$group_id} -> {$item} exists, skipping.\n"; return; }
        try { $perms->add_group_object($group_id, $section, $item, $type); } catch (Exception $e) { echo "Warning: add_group_object failed for {$item}: " . $e->getMessage() . "\n"; }
    }

    // Group items
    safe_add_group_object($perms, $all_mods, 'app', 'admin', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'calendar', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'companies', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'events', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'contacts', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'departments', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'files', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'file_folders', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'forums', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'help', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'projects', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'system', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'tasks', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'task_log', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'ticketsmith', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'public', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'roles', 'axo');
    safe_add_group_object($perms, $all_mods, 'app', 'users', 'axo');

    // admin/mod groups
    safe_add_group_object($perms, $admin_mods, 'app', 'admin', 'axo');
    safe_add_group_object($perms, $admin_mods, 'app', 'system', 'axo');
    safe_add_group_object($perms, $admin_mods, 'app', 'roles', 'axo');
    safe_add_group_object($perms, $admin_mods, 'app', 'users', 'axo');

    // non-admin
    safe_add_group_object($perms, $non_admin_mods, 'app', 'calendar', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'events', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'companies', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'contacts', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'departments', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'files', 'axo');
    safe_add_group_object($perms, $non_admin_mods, 'app', 'file_folders', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'forums', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'help', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'projects', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'tasks', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'task_log', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'ticketsmith', 'axo');
    $perms->add_group_object($non_admin_mods, 'app', 'public', 'axo');

    // Default ACLs
    $login_perms = array();
    $login_perms['system'] = array('login');

    $all_perms = array();
    $all_perms['application'] = array('access', 'add', 'edit', 'view', 'delete');

    $access_perms = array();
    $access_perms['application'] = array('access');

    $view_perms = array();
    $view_perms['application'] = array('access', 'view');

    $acl_perms = array();
    $acl_perms['sys'] = array('acl');

    $perms->add_acl($login_perms, null, array($role), null, null, 1, 1, null, null, 'user');

    // Administrator has ALL on ALL
    $perms->add_acl($all_perms, null, array($admin_role), null, array($all_mods), 1, 1, null, null, 'user');
    $perms->add_acl($access_perms, null, array($admin_role), $acl_perms, null, 1, 1, null, null, 'user');

    // Guest and other roles
    $perms->add_acl($view_perms, null, array($guest_role), null, array($non_admin_mods), 1, 1, null, null, 'user');
    $perms->add_acl($access_perms, null, array($anon_role), null, array($non_admin_mods), 1, 1, null, null, 'user');
    $perms->add_acl($all_perms, null, array($worker_role), null, array($non_admin_mods), 1, 1, null, null, 'user');

    $perms->add_acl($view_perms, null, array($worker_role, $guest_role), array('app' => array('users')), null, 1, 1, null, null, 'user');

    echo "Default GACL structure creation complete.\n";
} else {
    echo "GACL tables appear to contain data, skipping default creation.\n";
}

// Ensure admin user exists
$adminExists = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}users WHERE user_id = '" . $adminUser . "'");
if (!$adminExists) {
    echo "ERROR: admin user with id {$adminUser} does not exist in users table. Aborting admin assignment.\n";
    exit(1);
}

// Ensure admin user has an ARO object
$aroId = $perms->get_object_id('user', $adminUser, 'aro');
if (!$aroId) {
    echo "Adding ARO object for user {$adminUser}...\n";
    $perms->add_object('user', dPgetUsernameFromID($adminUser), $adminUser, 1, 0, 'aro');
} else {
    echo "ARO object for user {$adminUser} already exists.\n";
}

// Ensure admin user is in Administrator group
// find admin role id
$adminRoleId = $perms->get_group_id('admin', null, 'aro');
if (!$adminRoleId) {
    echo "ERROR: Administrator ARO group not found.\n";
} else {
    // Check membership
    $q = new DBQuery;
    $q->addTable('gacl_groups_aro_map');
    $q->addWhere('group_id = ' . (int)$adminRoleId . " AND aro_id = (SELECT id FROM {$dbprefix}gacl_aro WHERE value = '" . $adminUser . "' LIMIT 1)");
    $exists = $q->loadList();
    if (!$exists) {
        echo "Adding user {$adminUser} to Administrator group...\n";
        $perms->add_group_object($adminRoleId, 'user', $adminUser, 'aro');
    } else {
        echo "User {$adminUser} already member of Administrator group.\n";
    }
}

// Regenerate dotpermissions
echo "Regenerating dotpermissions...\n";
$perms->regeneratePermissions();

echo "Diagnostics after regeneration:\n";
$tables = [
    'gacl_aro', 'gacl_aro_groups', 'gacl_axo', 'gacl_axo_groups', 'gacl_aco', 'gacl_acl', 'dotpermissions'
];
foreach ($tables as $t) {
    $cnt = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}{$t}");
    echo str_pad($t, 20) . ": " . intval($cnt) . "\n";
}

// Show sample dotpermissions rows for admin
$rows = $db->GetArray("SELECT user_id, section, axo, permission, allow, priority, enabled FROM {$dbprefix}dotpermissions WHERE user_id = '" . $adminUser . "' LIMIT 50");
if (!$rows) {
    echo "No dotpermissions rows found for user {$adminUser}.\n";
} else {
    echo "Sample dotpermissions for user {$adminUser}:\n";
    foreach ($rows as $r) {
        printf("%s | %s | %s | %s | allow=%d pri=%d en=%d\n", $r['user_id'], $r['section'], $r['axo'], $r['permission'], $r['allow'], $r['priority'], $r['enabled']);
    }
}

// Ensure app/admin/access exists for admin user
$hasAdmin = $db->GetOne("SELECT COUNT(*) FROM {$dbprefix}dotpermissions WHERE user_id = '" . $adminUser . "' AND section = 'app' AND axo = 'admin' AND permission = 'access' AND allow = 1");
if ($hasAdmin) {
    echo "User {$adminUser} has 'app/admin/access'.\n";
} else {
    echo "User {$adminUser} DOES NOT have 'app/admin/access'. Attempting to add an explicit allow record.\n";
    $db->Execute("INSERT INTO {$dbprefix}dotpermissions (acl_id,user_id,section,axo,permission,allow,priority,enabled) VALUES (0, '" . $adminUser . "', 'app', 'admin', 'access', 1, 1, 1)");
    echo "Inserted explicit allow for 'app/admin/access'.\n";
}

echo "Done. You may need to clear caches or reload web server to see changes.\n";

?>