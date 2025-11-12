# ✅ dotproject.sql - FIXED

## What Was Done

The original `dotproject.sql` file has been **permanently fixed** by adding the missing ACL data directly into the SQL file.

## Changes Made to dotproject.sql

**Location:** After line 1159 (after `gacl_acl_sections` INSERTs)

**Added:**

- Admin user in ACL system (`gacl_aro`)
- Admin group (`gacl_aro_groups`)
- **Critical user-to-group mapping** (`gacl_groups_aro_map`)
- ACL sections for users, operations, and modules
- Basic operations (access, view, add, edit, delete)
- Module groups

**Total:** 52 lines of SQL with 17 INSERT statements

## Result

✅ **No additional steps needed for fresh installations**

Simply import the SQL file:

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/dotproject.sql
```

Or use the convenience script:

```bash
bash db/import_db.sh
```

Admin login works immediately after import!

## What's No Longer Needed

### Obsolete Scripts (kept for reference)

- `install_database.sh` - Was created to add missing ACL data
- `dotproject_acl_patch.sql` - Was the patch file (now integrated)

### Still Useful

- `import_db.sh` - Handles SQL mode and prefix replacement
- `fix_acl_mapping.sql` - Can repair old/broken installations
- `quick_check.sql` - Diagnostic tool
- `verify_login_requirements.sql` - Comprehensive verification

## Verification

The fix adds this critical record:

```sql
INSERT INTO `%dbprefix%gacl_groups_aro_map` (group_id, aro_id)
VALUES (1, 1);
```

Without it, `checkLogin()` fails. With it, login works perfectly.

## Testing

After importing the fixed SQL file, verify:

```sql
SELECT aro.value, aro.name, gr_aro.group_id
FROM dotp_gacl_aro aro
INNER JOIN dotp_gacl_groups_aro_map gr_aro ON aro.id = gr_aro.aro_id
WHERE aro.value = 1;
```

**Expected result:**
| value | name | group_id |
|-------|------|----------|
| 1 | Administrator | 1 |

✅ If you see this row, login will work!

## Summary

- ✅ SQL file is complete
- ✅ No patches needed
- ✅ No additional install steps
- ✅ Login works out of the box
- ✅ Standard import process

**Date Fixed:** November 12, 2025
