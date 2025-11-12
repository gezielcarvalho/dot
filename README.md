# dotProject

**Version:** 2.2.0 (Legacy)  
**Status:** ⚠️ Legacy Application - Modernization in Progress  
**PHP Version:** 7.4 (via Docker)  
**Database:** MySQL 5.7

dotProject is an open source project management system written in PHP. It originally started in 2001 by dotMarketing on SourceForge and has been under the watchful eye of the current dotProject team since around December 2002.

> **⚠️ Important Notice:** This is a legacy application running on outdated technology. This repository is being used to modernize the codebase. See [docs/ACTION_PLAN.md](docs/ACTION_PLAN.md) for the complete modernization roadmap.

---

## 🚀 Quick Start (Docker)

### Prerequisites

- Docker Desktop installed
- Docker Compose installed
- 2GB free RAM
- 5GB free disk space

### Getting Started

1. **Clone the repository**

   ```bash
   git clone <repository-url>
   cd dot
   ```

2. **Start the Docker containers**

   ```bash
   docker-compose up -d
   ```

3. **Initialize the database**

   ```bash
   # Import the complete database schema (includes ACL data for admin login)
   docker exec -i db mysql -uroot -pA_1234567 dotproject < db/dotproject.sql

   # Or use the convenience script:
   bash db/import_db.sh
   ```

4. **Configure the application**

   ```bash
   # Copy the configuration template
   docker exec -it dot bash -c "cp /var/www/html/includes/config-dist.php /var/www/html/includes/config.php"

   # Update database credentials in includes/config.php
   # The Docker setup uses these values:
   # - dbhost: db
   # - dbuser: root
   # - dbpass: A_1234567
   # - dbname: dotproject
   ```

5. **Set file permissions**

   ```bash
   docker exec -it dot bash -c "chmod -R 777 /var/www/html/files /var/www/html/tmp"
   ```

6. **Access the application**

   - **Main Application:** http://localhost:8000
   - **phpMyAdmin:** http://localhost:8088
     - Username: `root`
     - Password: `A_1234567`

7. **Complete web installation**
   - Navigate to http://localhost:8000
   - Follow the installation wizard
   - Create your admin account

---

## 🏗️ Architecture Overview

### Core Components

- **PHP 7.4** - Application runtime
- **Apache** - Web server
- **MySQL 5.7** - Database
- **ADOdb** - Database abstraction layer
- **Smarty** - Template engine
- **jQuery** - Frontend JavaScript

### Directory Structure

```
dot/
├── modules/          # Application modules (projects, tasks, etc.)
├── classes/          # Core PHP classes
├── includes/         # Core includes and configuration
├── lib/             # Third-party libraries
├── db/              # Database schemas and migrations
├── style/           # UI themes and styles
├── files/           # Uploaded files (user content)
├── tmp/             # Temporary files
├── docs/            # Documentation
├── docker-compose.yaml
├── Dockerfile
└── index.php        # Application entry point
```

### Available Modules

- **Projects** - Project management and tracking
- **Tasks** - Task creation and assignment
- **Calendar** - Event and milestone scheduling
- **Companies** - Client/company management
- **Contacts** - Contact information
- **Files** - Document management
- **Forums** - Discussion boards
- **Resources** - Resource allocation
- **Risks** - Risk management
- **TicketSmith** - Issue tracking

---

## 📋 Current State

### What Works

✅ Core project management functionality  
✅ Task creation and assignment  
✅ User authentication and permissions  
✅ File uploads and management  
✅ Calendar and scheduling  
✅ Company and contact management  
✅ Basic reporting

### Known Issues

⚠️ **Security:** Uses deprecated PHP patterns, potential SQL injection  
⚠️ **Performance:** No caching, slow database queries  
⚠️ **Mobile:** Not responsive, poor mobile experience  
⚠️ **Dependencies:** Outdated libraries with known vulnerabilities  
⚠️ **Code Quality:** No tests, mixed coding standards  
⚠️ **Documentation:** Limited API and developer docs

---

## 🛠️ Development

### Local Development Setup

1. **Start containers in development mode**

   ```bash
   docker-compose up
   ```

2. **Access container shell**

   ```bash
   docker exec -it dot bash
   ```

3. **View logs**

   ```bash
   # Application logs
   docker-compose logs -f dot

   # Database logs
   docker-compose logs -f db
   ```

4. **Stop containers**
   ```bash
   docker-compose down
   ```

### Database Management

**Backup Database**

```bash
docker exec db mysqldump -uroot -pA_1234567 dotproject > backup_$(date +%Y%m%d).sql
```

**Restore Database**

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < backup_20251111.sql
```

**Access MySQL CLI**

```bash
docker exec -it db mysql -uroot -pA_1234567 dotproject
```

---

## 📚 Documentation

### Essential Reading

- **[Action Plan](docs/ACTION_PLAN.md)** - Complete modernization roadmap
- **[Contributing](CONTRIBUTING.md)** - How to contribute
- **[License](LICENSE)** - GPL License information
- **[Changelog](ChangeLog)** - Historical changes

### External Resources

- **Original Repository:** https://github.com/dotproject/dotProject
- **Support Forums:** http://forums.dotproject.net/index.php
- **Issue Tracker:** https://github.com/dotproject/dotProject/issues

---

## 🔐 Security Considerations

> **⚠️ WARNING:** This application has known security vulnerabilities. Do NOT expose to the public internet without proper security hardening.

### Immediate Security Actions Required

1. Change default database password
2. Implement HTTPS in production
3. Update all default credentials
4. Apply security patches from ACTION_PLAN.md
5. Conduct security audit

### Recommended for Production

- Use strong passwords (20+ characters)
- Enable firewall rules
- Implement fail2ban or similar
- Regular security updates
- Database backups (daily)
- Web Application Firewall (WAF)

---

## 🗺️ Modernization Roadmap

This project is undergoing systematic modernization. See [docs/ACTION_PLAN.md](docs/ACTION_PLAN.md) for details.

### Planned Phases

**Phase 0: Get Running** (2-3 weeks)  
✅ Docker setup  
✅ Documentation  
⬜ Security audit  
⬜ Basic testing

**Phase 1: Stabilization** (2 months)  
⬜ PHP 8.2 upgrade  
⬜ Security hardening  
⬜ Testing framework  
⬜ CI/CD pipeline

**Phase 2: Code Modernization** (3 months)  
⬜ Composer dependencies  
⬜ Namespaces & autoloading  
⬜ Modern ORM  
⬜ Code quality tools

**Phase 3: Frontend Modernization** (2 months)  
⬜ Responsive design  
⬜ Modern JavaScript  
⬜ Accessibility  
⬜ Performance optimization

**Phase 4: API & Integration** (2 months)  
⬜ REST API  
⬜ Authentication (JWT)  
⬜ Webhooks  
⬜ Third-party integrations

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

### How to Help

- 🐛 Report bugs and security issues
- 📝 Improve documentation
- 🧪 Write tests for existing code
- ✨ Implement modernization tasks from ACTION_PLAN.md
- 🎨 Improve UI/UX

### Development Workflow

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Test thoroughly
5. Commit with clear messages
6. Push to your fork
7. Open a Pull Request

---

## 📊 Project Status

### Statistics

- **Lines of Code:** ~200,000+
- **Modules:** 20+
- **Database Tables:** 50+
- **Active Since:** 2001 (24+ years)
- **Last Major Release:** v2.2.0

### Current Focus

🎯 Security hardening and vulnerability fixes  
🎯 Documentation and knowledge transfer  
🎯 Setting up testing infrastructure  
🎯 Planning PHP 8.2 migration

---

## 🆘 Support

### Getting Help

- **Documentation Issues:** Open an issue in this repository
- **Bug Reports:** Use GitHub Issues with detailed reproduction steps
- **Security Vulnerabilities:** Email security contact (see CONTRIBUTING.md)
- **General Questions:** Check docs/ACTION_PLAN.md first

### Community

- **Original Forums:** http://forums.dotproject.net/index.php
- **IRC:** `#dotproject` on `irc.freenode.net` (legacy, may be inactive)

---

## 📄 License

**GPL (GNU General Public License)**

As of version 2.0, dotProject is released under GPL.  
Version 1.0.2 and previous versions were released under BSD license.

Parts of dotProject include libraries from other projects which are used and re-released under their original licenses. See individual library directories in `/lib` for specific license information.

---

## 🙏 Acknowledgments

- Original dotProject team and contributors (2001-present)
- All open source libraries included in this project
- The PHP community for continued support

---

**Last Updated:** November 11, 2025  
**Maintainer:** Development Team  
**Repository:** This fork focuses on modernization efforts
