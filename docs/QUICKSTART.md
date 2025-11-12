# Quick Start Guide - dotProject

This guide will help you get dotProject running on your local machine using Docker in under 10 minutes.

## Prerequisites

Before starting, ensure you have:

- **Docker Desktop** installed and running ([Download here](https://www.docker.com/products/docker-desktop))
- **Git** installed ([Download here](https://git-scm.com/downloads))
- At least **2GB of free RAM**
- At least **5GB of free disk space**

## Step-by-Step Setup

### 1. Clone the Repository

```bash
git clone <your-repository-url>
cd dot
```

### 2. Start Docker Containers

```bash
docker-compose up -d
```

This will start three containers:

- **dot** - The application (Apache + PHP 7.4)
- **db** - MySQL 5.7 database
- **myadmin** - phpMyAdmin for database management

Wait for about 30 seconds for containers to fully initialize.

### 3. Verify Containers Are Running

```bash
docker ps
```

You should see three containers running:

- `dot` on port 8000
- `db` on port 3306 (internal)
- `myadmin` on port 8088

### 4. Initialize Database

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/dotproject.sql
```

Expected output: You'll see a series of database creation statements. This is normal.

### 5. Create Configuration File

#### Windows (PowerShell):

```powershell
docker exec -it dot bash -c "cp /var/www/html/includes/config-dist.php /var/www/html/includes/config.php"
```

#### Mac/Linux:

```bash
docker exec -it dot bash -c "cp /var/www/html/includes/config-dist.php /var/www/html/includes/config.php"
```

### 6. Update Database Credentials

You need to edit `includes/config.php` to set the correct database connection details.

#### Option A: Edit in Container

```bash
docker exec -it dot bash
nano /var/www/html/includes/config.php
```

#### Option B: Edit Locally

Open `includes/config.php` in your text editor and update these lines:

```php
$dPconfig['dbtype'] = 'mysqli';
$dPconfig['dbhost'] = 'db';           // Changed from 'localhost'
$dPconfig['dbname'] = 'dotproject';
$dPconfig['dbprefix'] = 'dotp_';
$dPconfig['dbuser'] = 'root';         // Changed from 'dp_user'
$dPconfig['dbpass'] = 'A_1234567';    // Changed from 'dp_pass'
$dPconfig['dbpersist'] = false;
```

Save the file.

### 7. Set File Permissions

```bash
docker exec -it dot bash -c "chmod -R 777 /var/www/html/files /var/www/html/tmp"
```

### 8. Access the Application

Open your web browser and navigate to:

**Main Application:** http://localhost:8000

You should see the dotProject login page or installation wizard.

**phpMyAdmin:** http://localhost:8088

- Server: `db`
- Username: `root`
- Password: `A_1234567`

### 9. Complete Installation

If you see the installation wizard:

1. Click through the system check
2. Confirm database settings (should be pre-filled)
3. Create your admin account:
   - **Username:** admin (or your choice)
   - **Password:** (choose a strong password)
   - **Email:** your-email@example.com
4. Click "Install"

### 10. Login

Once installation is complete:

1. Go to http://localhost:8000
2. Login with your admin credentials
3. Explore the application!

## Common Issues & Solutions

### Issue: "Cannot connect to database"

**Solution:**

1. Verify containers are running: `docker ps`
2. Check database credentials in `includes/config.php`
3. Ensure `dbhost` is set to `db` (not `localhost`)
4. Restart containers: `docker-compose restart`

### Issue: "Permission denied" errors

**Solution:**

```bash
docker exec -it dot bash -c "chmod -R 777 /var/www/html/files /var/www/html/tmp"
docker-compose restart dot
```

### Issue: Port already in use (8000 or 8088)

**Solution:**
Edit `docker-compose.yaml` and change the ports:

```yaml
ports:
  - "8001:80" # Changed from 8000:80
```

Then restart:

```bash
docker-compose down
docker-compose up -d
```

### Issue: "includes/config.php not found"

**Solution:**
The config file wasn't created properly. Run:

```bash
docker exec -it dot bash -c "ls -la /var/www/html/includes/config*"
```

If you don't see `config.php`, run step 5 again.

### Issue: Database already exists error

**Solution:**
The database was already initialized. You can either:

- Skip step 4 (database import)
- Or drop and recreate:

```bash
docker exec -it db mysql -uroot -pA_1234567 -e "DROP DATABASE IF EXISTS dotproject; CREATE DATABASE dotproject;"
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/dotproject.sql
```

## Useful Commands

### View Application Logs

```bash
docker-compose logs -f dot
```

### View Database Logs

```bash
docker-compose logs -f db
```

### Access Application Container Shell

```bash
docker exec -it dot bash
```

### Access Database Shell

```bash
docker exec -it db mysql -uroot -pA_1234567 dotproject
```

### Stop All Containers

```bash
docker-compose down
```

### Restart All Containers

```bash
docker-compose restart
```

### Remove Everything (including data)

```bash
docker-compose down -v
```

⚠️ **Warning:** This deletes ALL data including the database!

## Next Steps

Now that you have dotProject running:

1. **Explore the application**

   - Create a test project
   - Add some tasks
   - Invite team members

2. **Read the documentation**

   - [ACTION_PLAN.md](ACTION_PLAN.md) - Full modernization roadmap
   - [README.md](../README.md) - Project overview

3. **Security hardening** (if deploying to production)

   - Change default database password
   - Review security checklist in ACTION_PLAN.md
   - Set up HTTPS

4. **Contribute**
   - Report bugs
   - Improve documentation
   - Submit pull requests

## Getting Help

- **Check documentation:** [docs/](.)
- **Report issues:** GitHub Issues
- **Review logs:** `docker-compose logs`

## Development Mode

For active development with live reload:

1. Edit files directly in your local directory
2. Changes are automatically reflected (Docker volume mount)
3. For PHP changes, you may need to: `docker-compose restart dot`

## Backup Your Data

Regular backups are essential:

```bash
# Backup database
docker exec db mysqldump -uroot -pA_1234567 dotproject > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup uploaded files
tar -czf files_backup_$(date +%Y%m%d_%H%M%S).tar.gz files/
```

## Restore from Backup

```bash
# Restore database
docker exec -i db mysql -uroot -pA_1234567 dotproject < backup_20251111_120000.sql

# Restore files
tar -xzf files_backup_20251111_120000.tar.gz
```

---

**You're all set!** 🎉

If you encounter any issues not covered here, please check the main [README.md](../README.md) or open an issue.
