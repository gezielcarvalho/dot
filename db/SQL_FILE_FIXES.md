# Required Fixes for dotproject.sql

## Problem Summary

The original `dotproject.sql` file has a **critical bug** that prevents admin login from working on fresh installations. While the file creates all necessary ACL tables, it fails to populate the required user-to-group mappings.

## Root Cause

The `checkLogin()` function in `classes/permissions.class.php` (line 73) checks if a user belongs to any group by querying:

```sql
SELECT aro.value, aro.name, gr_aro.group_id
FROM gacl_aro aro
INNER JOIN gacl_groups_aro_map gr_aro ON aro.id = gr_aro.aro_id
WHERE aro.value = <user_id>
```

If this query returns **no rows**, login fails even with the correct password.

## What's Missing in dotproject.sql

### Current State (Lines 1101-1112)

```sql
CREATE TABLE `%dbprefix%gacl_groups_aro_map` (
  `group_id` int(11) NOT NULL default '0',
  `aro_id` int(11) NOT NULL default '0',
  PRIMARY KEY  (`group_id`,`aro_id`)
);
```

**No INSERT statements!** The table is created but left empty.

### What's Needed

After line 1159 (after the `gacl_acl_sections` INSERTs), add:

```sql
-- ============================================
-- Critical ACL Data (REQUIRED FOR LOGIN)
-- ============================================

-- Create admin user in ACL system
INSERT INTO `%dbprefix%gacl_aro` (id, section_value, value, order_value, name, hidden)
VALUES (1, 'user', '1', 1, 'Administrator', 0);

-- Create admin group
INSERT INTO `%dbprefix%gacl_aro_groups` (id, parent_id, lft, rgt, name, value)
VALUES (1, 0, 1, 2, 'Administrator', 'admin');

-- CRITICAL: Map admin user to admin group
-- Without this, checkLogin() fails!
INSERT INTO `%dbprefix%gacl_groups_aro_map` (group_id, aro_id)
VALUES (1, 1);

-- Create required ACL sections
INSERT INTO `%dbprefix%gacl_aro_sections` (id, value, order_value, name, hidden)
VALUES (1, 'user', 1, 'User', 0);

INSERT INTO `%dbprefix%gacl_aco_sections` (id, value, order_value, name, hidden)
VALUES (1, 'application', 1, 'Application', 0);

INSERT INTO `%dbprefix%gacl_axo_sections` (id, value, order_value, name, hidden)
VALUES
(1, 'sys', 1, 'System', 0),
(2, 'app', 2, 'Application', 0);

-- Create basic operations (ACO)
INSERT INTO `%dbprefix%gacl_aco` (id, section_value, value, order_value, name, hidden)
VALUES
(1, 'application', 'access', 1, 'Access', 0),
(2, 'application', 'view', 2, 'View', 0),
(3, 'application', 'add', 3, 'Add', 0),
(4, 'application', 'edit', 4, 'Edit', 0),
(5, 'application', 'delete', 5, 'Delete', 0);

-- Create module groups (AXO groups)
INSERT INTO `%dbprefix%gacl_axo_groups` (id, parent_id, lft, rgt, name, value)
VALUES
(1, 0, 1, 2, 'All Modules', 'all'),
(2, 0, 3, 4, 'System', 'sys'),
(3, 0, 5, 6, 'Modules', 'mod');
```

## Tables That Need Data

| Table                 | Current State              | Required State                               |
| --------------------- | -------------------------- | -------------------------------------------- |
| `gacl_aro`            | ❌ Empty                   | ✅ Needs admin user (id=1)                   |
| `gacl_aro_groups`     | ❌ Empty                   | ✅ Needs admin group (id=1)                  |
| `gacl_groups_aro_map` | ❌ Empty                   | ✅ **CRITICAL:** Needs user-to-group mapping |
| `gacl_aro_sections`   | ❌ Empty                   | ✅ Needs 'user' section                      |
| `gacl_aco_sections`   | ⚠️ Partial (has 2 entries) | ✅ Needs 'application' section               |
| `gacl_axo_sections`   | ❌ Empty                   | ✅ Needs 'sys' and 'app' sections            |
| `gacl_aco`            | ❌ Empty                   | ✅ Needs basic operations                    |
| `gacl_axo_groups`     | ❌ Empty                   | ✅ Needs module groups                       |

## Why This Matters

### Login Flow Breakdown

```
1. User enters username/password
   ↓
2. Password verified ✅
   ↓
3. checkLogin($user_id) called
   ↓
4. Query: SELECT from gacl_aro JOIN gacl_groups_aro_map
   ↓
5. If NO ROWS → Login fails ❌
   If HAS ROWS → Login succeeds ✅
```

Without the mapping in `gacl_groups_aro_map`, step 5 always fails.

## Implementation Options

### Option 1: Patch dotproject.sql (Recommended)

Apply the patch from `db/dotproject_acl_patch.sql` to the original SQL file.

**Location to insert:** After line 1159, before the modules section.

### Option 2: Use Fixed Installation Script

Use `db/install_database.sh` which:

- Imports original dotproject.sql
- Automatically adds missing ACL data
- Verifies installation success

### Option 3: Manual Fix After Import

If database is already imported, run:

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/fix_acl_mapping.sql
```

## Testing the Fix

After applying any fix, verify with:

```sql
-- Should return 1 row
SELECT aro.value, aro.name, gr_aro.group_id
FROM dotp_gacl_aro aro
INNER JOIN dotp_gacl_groups_aro_map gr_aro ON aro.id = gr_aro.aro_id
WHERE aro.value = 1;
```

**Expected result:**
| value | name | group_id |
|-------|------|----------|
| 1 | Administrator | 1 |

If this returns **no rows**, login will fail.

## Recommended Actions

1. **For new installations:**

   - Use `db/install_database.sh` instead of direct SQL import
   - Or patch `dotproject.sql` with `dotproject_acl_patch.sql`

2. **For existing installations:**

   - Check if mapping exists: `SELECT * FROM dotp_gacl_groups_aro_map WHERE aro_id=1;`
   - If empty, run `db/fix_acl_mapping.sql`

3. **For distribution:**
   - Submit patch to dotProject project to fix the original SQL file
   - Update installation documentation to mention this issue

## Files Reference

- **Patch file:** `db/dotproject_acl_patch.sql` - SQL to add to dotproject.sql
- **Installation script:** `db/install_database.sh` - Automated fixed installation
- **Repair script:** `db/fix_acl_mapping.sql` - Fix for existing installations
- **Verification:** `db/quick_check.sql` - Quick diagnostic

## Related Documentation

- `docs/LOGIN_DIAGNOSIS.md` - Technical details of login process
- `docs/REQUIRED_RECORDS.md` - Complete database requirements
- `docs/LOGIN_ISSUE_RESOLUTION.md` - How this issue was discovered and fixed
