# Analysis Complete: Original SQL File Issues

## Summary

After troubleshooting the login issue, I've identified **critical bugs in the original `dotproject.sql` file** that cause fresh installations to fail.

## What's Wrong with dotproject.sql

### The Bug

The original SQL file creates ACL tables but **fails to populate** the user-to-group mapping table (`gacl_groups_aro_map`). This causes the `checkLogin()` function to fail, preventing admin login even with the correct password.

### Affected Tables

- ❌ `gacl_aro` - Empty (needs admin user)
- ❌ `gacl_aro_groups` - Empty (needs admin group)
- ❌ `gacl_groups_aro_map` - **CRITICAL:** Empty (needs mapping)
- ❌ `gacl_aro_sections` - Empty
- ❌ `gacl_aco` - Empty (operations)
- ❌ `gacl_axo_groups` - Empty (module groups)

### Impact

🔴 **Login fails on every fresh installation** - Admin cannot log in even with correct password.

## Solutions Created

### 1. Patch File - `db/dotproject_acl_patch.sql`

- Contains the missing INSERT statements
- Can be applied to the original SQL file
- Adds all required ACL data

### 2. Fixed Installation Script - `db/install_database.sh` ⭐ RECOMMENDED

- Imports dotproject.sql automatically
- Adds missing ACL data
- Verifies installation success
- **Use this for new installations**

### 3. Repair Script - `db/fix_acl_mapping.sql`

- Fixes existing broken installations
- Run if database already imported

### 4. Diagnostic Scripts

- `quick_check.sql` - Fast health check
- `verify_login_requirements.sql` - Comprehensive verification

## Documentation Created

### `db/SQL_FILE_FIXES.md`

Complete technical analysis:

- What's missing from dotproject.sql
- Why login fails
- Exact SQL to add
- Where to insert it (after line 1159)
- Testing procedures

### Updated `db/README.md`

- Notes the SQL file bug
- Recommends install_database.sh
- Links to fix documentation

## Root Cause Analysis

### The Login Check Code

```php
// classes/permissions.class.php line 73
function checkLogin($login) {
    $q = new DBQuery;
    $q->addQuery('aro.value,aro.name, gr_aro.group_id');
    $q->addTable('gacl_aro', 'aro');
    $q->innerJoin('gacl_groups_aro_map', 'gr_aro', 'aro.id=gr_aro.aro_id');
    $q->addWhere('aro.value=' . (int)$login);
    $arr=$q->loadHash();
    return $arr?1:0;  // Returns 0 if no rows found!
}
```

If `gacl_groups_aro_map` is empty, the JOIN returns no rows → `checkLogin()` returns 0 → login fails.

### The Missing Data

```sql
-- This ONE record is critical:
INSERT INTO gacl_groups_aro_map (group_id, aro_id)
VALUES (1, 1);
```

Without this mapping, the admin user (aro_id=1) doesn't belong to any group, failing the login check.

## Files Reference

| File                            | Purpose         | Status      |
| ------------------------------- | --------------- | ----------- |
| `dotproject.sql`                | Original schema | ❌ Has bugs |
| `dotproject_acl_patch.sql`      | Fix patch       | ✅ Created  |
| `install_database.sh`           | Fixed installer | ✅ Created  |
| `fix_acl_mapping.sql`           | Repair script   | ✅ Created  |
| `SQL_FILE_FIXES.md`             | Technical docs  | ✅ Created  |
| `quick_check.sql`               | Diagnostic      | ✅ Created  |
| `verify_login_requirements.sql` | Full diagnostic | ✅ Created  |

## Recommendations

### For New Installations

✅ **Use `bash db/install_database.sh`**

- Handles all fixes automatically
- Verifies success
- No manual intervention needed

### For Fixing Existing Installs

✅ **Run `db/fix_acl_mapping.sql`**

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/fix_acl_mapping.sql
```

### For Upstream Contribution

Consider submitting the patch (`dotproject_acl_patch.sql`) to the dotProject project maintainers to fix the issue in the official distribution.

## Key Takeaways

1. **Original SQL file is incomplete** - Missing critical ACL data
2. **Login fails by design** - Without ACL mappings, checkLogin() always returns false
3. **Multiple solutions provided** - Patch, fixed installer, and repair script
4. **Documented thoroughly** - Complete analysis and fix instructions
5. **Preventable** - Using install_database.sh prevents the issue entirely

---

**Date:** November 12, 2025  
**Issue:** Incomplete ACL data in dotproject.sql  
**Status:** ✅ Analyzed, documented, and fixed
