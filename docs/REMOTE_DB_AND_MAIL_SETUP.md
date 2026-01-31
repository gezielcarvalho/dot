# Remote MySQL (Aiven) & Mail (MailHog) Integration

Summary of changes applied to support using an external Aiven MySQL service and to restore mail functionality during the migration.

## Key changes

- Environment-driven DB config
  - `includes/config.php` now reads DB connection values from environment variables: `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_TYPE`, `DB_PREFIX`.
  - Added `MYSQL_SSL_CA` and `MYSQL_SSL_MODE` to support SSL/TLS connections to Aiven MySQL.

- ADODB / MySQLi SSL integration
  - `includes/db_adodb.php` and `lib/adodb/drivers/adodb-mysqli.inc.php` now honor `ssl_ca` and `ssl_mode`, calling `mysqli_ssl_set()` and setting `MYSQLI_OPT_SSL_VERIFY_SERVER_CERT` where available.

- Docker & env
  - `docker-compose.yaml` updated to pass DB and MAIL environment variables into the `dot` container and to mount `./certs` into `/certs` read-only.
  - `.env.example` updated with placeholders for DB and mail settings (`MAIL_TRANSPORT`, `MAIL_HOST`, `MAIL_PORT`, etc.) and a MySQL CA path example.

- Tools for testing and provisioning
  - `tools/test_db.php` — quick PHP script to test mysqli connection with optional SSL CA.
  - `tools/check_mail.php` — prints effective mail config, raw env values and can send a test message; use `--send` to attempt a send and `--verbose` to show the SMTP transaction log.
  - `tools/install_schema.php` — idempotent installer to import the schema (`db/dotproject.sql`) and insert required admin records; supports `--drop` to remove tables with configured prefix and `--force` to skip safety checks.

- Small fixes and cleanups
  - Fixed a deprecation in `includes/db_adodb.php` function signature.
  - `includes/db_connect.php` now allows mail config to be overridden via environment variables (useful to force MailHog in containers).
  - Updated `.gitignore` to avoid committing certificates and sensitive files (general patterns already present).

---

## How to use the tools

1. DB connection test (inside container):

```
docker exec dot php tools/test_db.php
```

2. Import schema (drops existing `DB_PREFIX` tables with `--drop`):

```
docker exec dot php tools/install_schema.php --drop
```

3. Check mail settings and send a test:

```
docker exec dot php tools/check_mail.php
# send test (no transaction log)
docker exec dot php tools/check_mail.php --send --to=you@example.com
# send with transaction (verbose)
docker exec dot php tools/check_mail.php --send --verbose --to=you@example.com
```

4. Quick SMTP connectivity check (run inside container):

```
# from host: docker exec dot bash -lc "php -r 'if($s=@fsockopen("mailhog",1025,$e,$m,5)){echo fgets($s);fwrite($s, "EHLO test\r\n"); echo fgets($s); fclose($s);}else{echo "CONNECT FAILED: $e - $m\n";}'"
```

---

## Notes / Caveats

- Aiven MySQL may enforce `sql_require_primary_key` or other server settings; the installer attempts to set session variables where possible, and provides hints if server-level changes are needed.
- SSL verification behavior depends on the PHP build and mysqli constants availability (e.g., `MYSQLI_OPT_SSL_VERIFY_SERVER_CERT`). If full host identity verification is required, consult Aiven docs and your PHP build.
- Do not add real secrets to the repository. Add credentials to an untracked `.env` file (the repo already mentions `.env` in `.gitignore`).

---

## Cleanup performed

- Removed noisy ad-hoc debug behaviors from `tools/check_mail.php` (transaction log printing is now optional via `--verbose`).
- Kept `tools/*` helper scripts (cleaned and documented) for future testing and debugging.

---

If you'd like, I can squash these changes into a single commit and open a PR (or clean up further by moving tests into a `dev-tools/` folder). Would you like me to prepare that?
