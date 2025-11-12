# Database Scripts

This folder contains database setup and maintenance scripts for dotProject.

## Main Database

- **`dotproject.sql`** - Full database schema with ACL data
  - ✅ **FIXED:** Now includes all required ACL mappings for admin login
  - Used automatically by the web installer (`/install/index.php`)

## Installation

**For fresh installations:**

1. Start Docker: `docker-compose up -d`
2. Open browser: http://localhost:8000
3. Follow the web installation wizard
4. Default admin credentials: **admin** / **passwd**

The installer automatically imports `dotproject.sql` with all required ACL data.

## Upgrade Scripts

- `upgrade_*.sql` - Database upgrade scripts for version migrations
- `upgrade_latest.sql` - Most recent upgrade script
- `upgrade_latest.php` - Data migration logic

## Notes

- All tables use the `dotp_` prefix by default
- **dotproject.sql is now complete** - includes all ACL data for admin login
- Web installer is the recommended installation method
- The SQL file is used by both web installer and manual imports

## Troubleshooting

If you have an **existing old installation** with login issues:

1. Check ACL mappings: Look for missing records in `gacl_groups_aro_map`
2. Run repair: `fix_acl_mapping.sql` (if it exists in your version)
3. See detailed diagnostics: `../docs/LOGIN_DIAGNOSIS.md`

For fresh installations using the web wizard, login should work out of the box.
