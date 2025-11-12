# Database Scripts

This folder contains database setup and maintenance scripts for dotProject.

## Main Database

- **`dotproject.sql`** - Full database schema with ACL data
  - ✅ **FIXED:** Now includes all required ACL mappings for admin login
  - Can be imported directly or use `import_db.sh` for convenience

## Utility Scripts

### Setup Scripts

- **`import_db.sh`** - Database import script
  - Imports dotproject.sql (now includes all ACL data)
  - Sets SQL mode for MySQL 5.7+ compatibility
  - Replaces table prefix placeholders
  - Usage: `bash db/import_db.sh`

### Legacy/Reference Scripts

- **`install_database.sh`** - Legacy installer (no longer needed)

  - Was created to add missing ACL data
  - Now obsolete since dotproject.sql is fixed
  - Kept for reference only

- **`dotproject_acl_patch.sql`** - ACL patch (no longer needed)

  - Was the patch applied to dotproject.sql
  - Now integrated into the main SQL file
  - Kept for documentation purposes

- **`fix_acl_mapping.sql`** - ACL repair for old installations

  - Creates admin user in ACL system
  - Maps admin user to admin group
  - Populates required ACL tables
  - Run if login fails with correct password

- **`verify_login_requirements.sql`** - Comprehensive login diagnostic

  - Checks all required database records
  - Verifies ACL mappings
  - Reports what's missing
  - Usage: `docker exec -i db mysql -uroot -pA_1234567 dotproject < db/verify_login_requirements.sql`

- **`quick_check.sql`** - Quick health check for login system
  - 7 essential checks
  - Fast diagnostic
  - Usage: Run in phpMyAdmin SQL tab

### Diagnostic Scripts

## Upgrade Scripts

- `upgrade_*.sql` - Database upgrade scripts for version migrations
- `upgrade_latest.sql` - Most recent upgrade script

## Notes

- All tables use the `dotp_` prefix by default
- **dotproject.sql is now complete** - includes all ACL data for admin login
- For MySQL 5.7+, use `import_db.sh` for proper SQL mode settings

## Quick Start

For a fresh installation:

```bash
bash db/import_db.sh
```

That's it! The SQL file now includes everything needed.

## Troubleshooting

If login fails (rare now that SQL file is fixed):

1. Run `quick_check.sql` to identify the issue
2. Run `verify_login_requirements.sql` for detailed diagnosis
3. If ACL mapping is still missing, run `fix_acl_mapping.sql`

For detailed troubleshooting steps, see `../docs/LOGIN_DIAGNOSIS.md`

## Documentation

- **`SQL_FILE_FIXES.md`** - Documents what was wrong with the original SQL file
  - Historical reference
  - Shows what was added to fix the login issue
- **`ANALYSIS_ORIGINAL_SQL.md`** - Root cause analysis of the original bug
