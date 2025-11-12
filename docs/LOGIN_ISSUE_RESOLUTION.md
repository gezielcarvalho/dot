# dotProject Login Issue - Resolution Summary

## Issue

Login failed with "Login Failed" message even though admin user existed in database.

## Root Cause

The admin user's password hash in `dotp_users` table had a **typo** - one character was missing.

**Incorrect hash (in database):** `76a2173be639254e72ffa4d6df1030a`  
**Correct hash (MD5 of 'passwd'):** `76a2173be6393254e72ffa4d6df1030a`  
**Difference:** Missing `3` at position 14

## Investigation Process

1. ✅ Verified database connection was working
2. ✅ Verified admin user existed in `dotp_users`
3. ✅ Verified admin contact existed in `dotp_contacts`
4. ✅ Verified ACL tables were populated correctly:
   - `dotp_gacl_aro` - User object existed
   - `dotp_gacl_aro_groups` - Admin group existed
   - `dotp_gacl_groups_aro_map` - User-to-group mapping existed
   - `dotp_dotpermissions` - 78 permissions loaded
5. ✅ Verified `checkLogin()` SQL query returned correct results
6. ❌ **FOUND:** Password authentication was failing due to hash mismatch

## Solution Applied

```sql
UPDATE dotp_users
SET user_password='76a2173be6393254e72ffa4d6df1030a'
WHERE user_username='admin';
```

## Login Credentials

- **URL:** http://localhost:8000
- **Username:** admin
- **Password:** passwd

## Files Created During Troubleshooting

### Documentation

- `docs/ACTION_PLAN.md` - Modernization roadmap
- `docs/QUICKSTART.md` - Installation guide
- `docs/SECURITY_CHECKLIST.md` - Security hardening guide
- `docs/DEVELOPER_GUIDE.md` - Developer onboarding
- `docs/INDEX.md` - Documentation hub
- `db/LOGIN_DIAGNOSIS.md` - Login process analysis
- `db/REQUIRED_RECORDS.md` - Database requirements

### Database Scripts (Keep These)

- `db/import_db.sh` - Database import with prefix replacement
- `db/fix_acl_mapping.sql` - ACL mapping fix (already applied)
- `db/verify_login_requirements.sql` - Diagnostic verification
- `db/quick_check.sql` - Quick health check
- `db/test_checklogin_query.sql` - Query testing

### Test Scripts (DELETED for security)

- ~~`test_checklogin.php`~~ - Removed
- ~~`test_full_login.php`~~ - Removed
- ~~`test_password.php`~~ - Removed
- ~~`rebuild_permissions.php`~~ - Removed

## Key Learnings

1. **Password Hash Verification:** Always verify MD5 hashes character-by-character when debugging authentication issues
2. **ACL System:** dotProject uses complex multi-table ACL system (phpGACL) that requires proper mapping between users, groups, and permissions
3. **Database Prefix:** All table names need `dotp_` prefix; DBQuery class handles this automatically
4. **Login Flow:**
   - Password check (SQLAuthenticator)
   - ACL permission check (checkLogin)
   - User data loading
   - Session initialization

## Status

✅ **RESOLVED** - Login working correctly with admin/passwd credentials

## Next Steps

1. Review and implement security recommendations from `docs/SECURITY_CHECKLIST.md`
2. Change default admin password
3. Review `docs/ACTION_PLAN.md` for modernization roadmap
4. Consider implementing the 6-phase modernization plan

## Date Resolved

November 12, 2025
