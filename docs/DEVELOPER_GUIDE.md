# Developer Guide - dotProject

Welcome to the dotProject development team! This guide will help you get started with contributing to the modernization effort.

**Last Updated:** November 11, 2025  
**Target Audience:** Developers contributing to dotProject modernization

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Development Environment](#development-environment)
3. [Code Structure](#code-structure)
4. [Coding Standards](#coding-standards)
5. [Development Workflow](#development-workflow)
6. [Testing](#testing)
7. [Common Tasks](#common-tasks)
8. [Troubleshooting](#troubleshooting)

---

## Getting Started

### Prerequisites

**Required:**

- Docker Desktop
- Git
- Text editor (VS Code recommended)
- Basic PHP knowledge
- Basic SQL knowledge

**Recommended:**

- PHP 7.4+ installed locally (for IDE support)
- Composer (for dependency management)
- Node.js (for future frontend work)

### Initial Setup

1. **Clone and setup**

   ```bash
   git clone <repository-url>
   cd dot
   docker-compose up -d
   ```

2. **Install the application** (see [QUICKSTART.md](QUICKSTART.md))

3. **Create your development branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Recommended VS Code Extensions

```json
{
  "recommendations": [
    "bmewburn.vscode-intelephense-client",
    "felixfbecker.php-debug",
    "phpstan.vscode-phpstan",
    "xdebug.php-debug",
    "ms-azuretools.vscode-docker",
    "editorconfig.editorconfig"
  ]
}
```

---

## Development Environment

### Docker Services

**Main Services:**

- `dot` - Apache + PHP 7.4 application server (port 8000)
- `db` - MySQL 5.7 database (internal port 3306)
- `myadmin` - phpMyAdmin (port 8088)

### Accessing Containers

```bash
# Application container
docker exec -it dot bash

# Database container
docker exec -it db bash

# Run MySQL client
docker exec -it db mysql -uroot -pA_1234567 dotproject
```

### Live Development

Files are mounted as volumes, so changes are immediately reflected:

- Edit files in your local editor
- Refresh browser to see changes
- PHP changes may require: `docker-compose restart dot`

### Debugging

#### Enable Xdebug (if needed)

1. Edit `Dockerfile`:

   ```dockerfile
   RUN pecl install xdebug-3.1.5
   RUN docker-php-ext-enable xdebug
   ```

2. Add to `php.ini`:

   ```ini
   [xdebug]
   xdebug.mode=debug
   xdebug.start_with_request=yes
   xdebug.client_host=host.docker.internal
   xdebug.client_port=9003
   ```

3. Rebuild container:
   ```bash
   docker-compose down
   docker-compose build
   docker-compose up -d
   ```

#### View Logs

```bash
# Application logs
docker-compose logs -f dot

# Database logs
docker-compose logs -f db

# Apache error log
docker exec dot tail -f /var/log/apache2/error.log

# Apache access log
docker exec dot tail -f /var/log/apache2/access.log
```

---

## Code Structure

### Directory Layout

```
dot/
├── index.php              # Main entry point
├── base.php              # Bootstrap file
├── includes/             # Core includes
│   ├── config.php        # Configuration (generated)
│   ├── config-dist.php   # Config template
│   ├── db_connect.php    # Database connection
│   ├── main_functions.php # Core functions
│   └── session.php       # Session handling
├── classes/              # Core classes
│   ├── ui.class.php      # UI components
│   ├── query.class.php   # Database query builder
│   └── permissions.class.php # Permission system
├── modules/              # Feature modules
│   ├── projects/         # Project management
│   ├── tasks/           # Task management
│   ├── companies/       # Company management
│   └── [other modules]
├── lib/                 # Third-party libraries
├── style/               # UI themes
├── db/                  # Database schemas
└── files/               # User uploads
```

### Module Structure

Each module follows this pattern:

```
modules/[module_name]/
├── index.php            # Module entry
├── addedit.php          # Create/edit form
├── view.php             # View details
├── [module].class.php   # Module class
├── do_[action]_aed.php  # Action handlers
└── setup.php            # Module configuration
```

### Request Flow

1. **Request arrives** at `index.php`
2. **Bootstrap** loads (`base.php`)
3. **Authentication** check
4. **Module routing** based on `?m=module&a=action` parameters
5. **Permission check** for module access
6. **Module execution** includes appropriate file
7. **Response** rendered via template system

---

## Coding Standards

### Current State (Legacy)

The existing codebase uses:

- Mixed short tags (`<?`) and long tags (`<?php`)
- Global variables heavily
- No namespaces
- Inconsistent naming (camelCase, snake_case, PascalCase)
- Procedural and OOP mixed

### Modernization Standards

For NEW code, follow these standards:

#### PHP Standards

```php
<?php
// Always use full PHP tags

namespace App\Modules\Projects;  // Use namespaces for new code

use App\Core\Database;           // Use statements

/**
 * Project class
 *
 * @package App\Modules\Projects
 */
class Project
{
    // Properties with visibility and type hints
    private int $id;
    private string $name;

    /**
     * Constructor with type hints
     */
    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    /**
     * Method with return type
     */
    public function save(): bool
    {
        // Use prepared statements for database
        $stmt = $db->prepare("UPDATE projects SET name = ? WHERE id = ?");
        return $stmt->execute([$this->name, $this->id]);
    }
}
```

#### Naming Conventions

- **Classes:** PascalCase (`ProjectManager`)
- **Methods/Functions:** camelCase (`getProjectById`)
- **Constants:** UPPER_SNAKE_CASE (`MAX_PROJECTS`)
- **Variables:** camelCase (`$projectId`)
- **Database tables:** snake_case with prefix (`dotp_projects`)

#### File Organization

- One class per file
- Filename matches class name
- Namespaces match directory structure

#### Security Best Practices

```php
// ❌ BAD - String concatenation
$sql = "SELECT * FROM users WHERE id = " . $_GET['id'];

// ✅ GOOD - Prepared statement
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);

// ❌ BAD - Direct output
echo $_POST['name'];

// ✅ GOOD - Sanitized output
echo htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8');

// ❌ BAD - No CSRF protection
<form method="post">

// ✅ GOOD - CSRF token
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
```

---

## Development Workflow

### Branch Strategy

```
main              # Production-ready code
├── develop       # Integration branch
│   ├── feature/xxx  # New features
│   ├── bugfix/xxx   # Bug fixes
│   └── refactor/xxx # Code improvements
```

### Making Changes

1. **Create feature branch**

   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/add-api-endpoint
   ```

2. **Make changes**

   - Write code following standards
   - Add tests (when testing framework is ready)
   - Update documentation

3. **Test locally**

   - Manual testing
   - Check for errors in logs
   - Verify database changes

4. **Commit changes**

   ```bash
   git add .
   git commit -m "feat: Add API endpoint for project listing

   - Implemented GET /api/projects
   - Added pagination support
   - Includes unit tests

   Closes #123"
   ```

5. **Push and create PR**
   ```bash
   git push origin feature/add-api-endpoint
   # Create Pull Request on GitHub
   ```

### Commit Message Format

Use conventional commits:

```
<type>: <subject>

<body>

<footer>
```

**Types:**

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style (formatting)
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Maintenance tasks

**Example:**

```
feat: Add project export to PDF

Implemented PDF export functionality using ezPDF library.
Users can now export project details including tasks and timeline.

Closes #456
```

---

## Testing

### Manual Testing

**Before submitting PR:**

- [ ] Test happy path
- [ ] Test error cases
- [ ] Test with different user roles
- [ ] Check database for data integrity
- [ ] Review error logs
- [ ] Test on fresh database

### Future: Automated Testing

Once PHPUnit is set up (Phase 1):

```php
<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Modules\Projects\Project;

class ProjectTest extends TestCase
{
    public function testProjectCreation()
    {
        $project = new Project(1, 'Test Project');
        $this->assertEquals('Test Project', $project->getName());
    }

    public function testProjectSave()
    {
        $project = new Project(1, 'Test Project');
        $result = $project->save();
        $this->assertTrue($result);
    }
}
```

---

## Common Tasks

### Adding a New Module

1. **Create module directory**

   ```bash
   mkdir modules/newmodule
   ```

2. **Create basic files**

   ```
   modules/newmodule/
   ├── index.php
   ├── setup.php
   └── newmodule.class.php
   ```

3. **Register module in database**
   ```sql
   INSERT INTO dotp_modules (mod_name, mod_directory, mod_active)
   VALUES ('NewModule', 'newmodule', 1);
   ```

### Adding a Database Table

1. **Create SQL file** in `db/`

   ```sql
   CREATE TABLE `dotp_your_table` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `name` varchar(255) NOT NULL,
     `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
   ```

2. **Document in migration** (for future use)

3. **Update schema documentation**

### Adding a New Page

1. **Create PHP file** in appropriate module

   ```php
   <?php
   // modules/projects/my_new_page.php

   $titleBlock = new CTitleBlock('My New Page', 'icon.png');
   $titleBlock->show();

   // Your content here
   ?>
   ```

2. **Add navigation link** (if needed)

3. **Set permissions** in database

### Database Operations

```php
// Legacy style (current codebase)
require_once $AppUI->getSystemClass('query');

$q = new DBQuery;
$q->addTable('projects');
$q->addQuery('*');
$q->addWhere('project_id = ' . (int)$project_id);
$result = $q->exec();

// Modern style (target for refactoring)
$stmt = $db->prepare("SELECT * FROM dotp_projects WHERE project_id = ?");
$stmt->execute([$project_id]);
$result = $stmt->fetch();
```

---

## Troubleshooting

### Common Issues

#### Issue: "White screen of death"

**Solution:**

1. Enable error reporting:
   ```php
   // In index.php, uncomment:
   error_reporting(E_ALL);
   ```
2. Check logs: `docker-compose logs dot`
3. Check Apache error log

#### Issue: "Class not found"

**Solution:**

- Check file path is correct
- Verify `require_once` statement
- Check file permissions
- Clear any opcode cache

#### Issue: "Database connection failed"

**Solution:**

1. Verify database is running: `docker ps`
2. Check credentials in `includes/config.php`
3. Ensure `dbhost` is `db` not `localhost`
4. Check database exists: `docker exec db mysql -uroot -pA_1234567 -e "SHOW DATABASES;"`

#### Issue: "Permission denied" on file operations

**Solution:**

```bash
docker exec -it dot bash -c "chown -R www-data:www-data /var/www/html/files /var/www/html/tmp"
docker exec -it dot bash -c "chmod -R 775 /var/www/html/files /var/www/html/tmp"
```

### Debugging Tips

1. **Use error logs**

   ```bash
   docker-compose logs -f dot
   ```

2. **Add debug output**

   ```php
   error_log("Debug: " . print_r($variable, true));
   ```

3. **Use var_dump strategically**

   ```php
   echo "<pre>";
   var_dump($data);
   echo "</pre>";
   die();
   ```

4. **Check database queries**

   - Enable MySQL query log
   - Use phpMyAdmin to test queries
   - Check `DBQuery` generated SQL

5. **Browser Developer Tools**
   - Network tab for AJAX calls
   - Console for JavaScript errors
   - Elements tab for DOM inspection

---

## Resources

### Documentation

- [ACTION_PLAN.md](ACTION_PLAN.md) - Modernization roadmap
- [QUICKSTART.md](QUICKSTART.md) - Getting started guide
- [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md) - Security guidelines
- [README.md](../README.md) - Project overview

### External Resources

- [PHP Manual](https://www.php.net/manual/en/)
- [MySQL Documentation](https://dev.mysql.com/doc/)
- [Docker Documentation](https://docs.docker.com/)
- [OWASP PHP Guide](https://owasp.org/www-project-php-security/)

### Tools

- **PHPStan** - Static analysis (to be set up)
- **PHP-CS-Fixer** - Code style (to be set up)
- **Composer** - Dependency management (to be set up)
- **Git** - Version control

---

## Getting Help

1. **Check documentation** in `docs/` directory
2. **Review existing code** for patterns
3. **Search closed issues** on GitHub
4. **Ask in team chat** (if available)
5. **Create GitHub issue** with details

---

## Contributing Checklist

Before submitting a pull request:

- [ ] Code follows standards (as much as possible)
- [ ] No debug code left in (var_dump, die, etc.)
- [ ] Tested manually
- [ ] No new security vulnerabilities introduced
- [ ] Database changes documented
- [ ] Comments added for complex logic
- [ ] Documentation updated if needed
- [ ] Commit messages are descriptive
- [ ] Branch is up to date with develop

---

## Next Steps

1. **Set up environment** - Follow QUICKSTART.md
2. **Explore codebase** - Browse modules/ and classes/
3. **Pick a task** - Check GitHub issues or ACTION_PLAN.md
4. **Make changes** - Follow this guide
5. **Submit PR** - Share your contribution!

---

**Welcome to the team!** 🎉

If you have questions or suggestions for improving this guide, please open an issue or submit a PR.

**Last Updated:** November 11, 2025  
**Maintainer:** Development Team
