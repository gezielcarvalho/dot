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

### Diagnostic & Repair Scripts

- **`fix_acl_mapping.sql`** - ACL repair for old installations

  - Use if you have an old installation with missing ACL data
  - Creates admin user in ACL system and mappings
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

- **`SQL_FILE_FIXED.md`** - Summary of the fix applied to dotproject.sql
  - Shows what was added
  - Explains why it was needed
  - Verification steps
