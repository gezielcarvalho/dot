# Database Scripts

This folder contains database setup and maintenance scripts for dotProject.

## Main Database

- **`dotproject.sql`** - Full database schema (original from dotProject distribution)

## Utility Scripts

### Setup & Diagnostic Scripts

- **`import_db.sh`** - Shell script to import database with proper settings

  - Sets SQL mode for MySQL 5.7 compatibility
  - Replaces table prefix placeholders
  - Usage: `bash import_db.sh`

- **`fix_acl_mapping.sql`** - Fixes ACL user-to-group mappings

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

## Upgrade Scripts

- `upgrade_*.sql` - Database upgrade scripts for version migrations
- `upgrade_latest.sql` - Most recent upgrade script

## Notes

- All tables use the `dotp_` prefix by default
- The import script handles table prefix replacement automatically
- For MySQL 5.7+, use `import_db.sh` instead of direct SQL import to avoid datetime errors

## Troubleshooting

If login fails:

1. Run `quick_check.sql` to identify the issue
2. If ACL mapping is missing, run `fix_acl_mapping.sql`
3. For complete diagnosis, run `verify_login_requirements.sql`

For detailed troubleshooting steps, see `../docs/LOGIN_DIAGNOSIS.md`
