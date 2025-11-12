# Security Checklist - dotProject

> ⚠️ **CRITICAL:** This application has known security vulnerabilities. Use this checklist to secure your installation.

**Last Updated:** November 11, 2025  
**Application Version:** dotProject 2.2.0

---

## Pre-Deployment Security Checklist

### ✅ Immediate Actions (Required)

#### 1. Change Default Credentials

- [ ] Change MySQL root password from `A_1234567`
  ```bash
  docker exec -it db mysql -uroot -pA_1234567 -e "ALTER USER 'root'@'%' IDENTIFIED BY 'YOUR_STRONG_PASSWORD_HERE';"
  ```
- [ ] Update password in `docker-compose.yaml` under `MYSQL_ROOT_PASSWORD`
- [ ] Update password in `includes/config.php` under `$dPconfig['dbpass']`
- [ ] Create non-root database user with limited privileges
- [ ] Change admin account password after first login

#### 2. File Permissions

- [ ] Set proper permissions on config file
  ```bash
  docker exec -it dot bash -c "chmod 640 /var/www/html/includes/config.php"
  ```
- [ ] Ensure `files/` directory is writable but not executable
- [ ] Set `tmp/` directory permissions correctly
- [ ] Remove write permissions from code directories

#### 3. Remove Development Tools

- [ ] Disable phpMyAdmin in production (`docker-compose.yaml`)
- [ ] Remove or disable phpinfo pages
- [ ] Disable error display in production
  ```php
  // In includes/config.php or php.ini
  display_errors = Off
  log_errors = On
  ```
- [ ] Remove test files and development scripts

#### 4. Network Security

- [ ] Do NOT expose MySQL port 3306 to public
- [ ] Use firewall to restrict access to port 8000
- [ ] Implement HTTPS/SSL (required for production)
- [ ] Configure Docker network isolation
- [ ] Disable unnecessary services

---

## Database Security

### ✅ Database Hardening

- [ ] Create dedicated database user (not root)
  ```sql
  CREATE USER 'dotproject_user'@'%' IDENTIFIED BY 'strong_password';
  GRANT SELECT, INSERT, UPDATE, DELETE ON dotproject.* TO 'dotproject_user'@'%';
  FLUSH PRIVILEGES;
  ```
- [ ] Remove anonymous MySQL users
- [ ] Disable MySQL remote root access
- [ ] Enable MySQL query logging (temporarily for audit)
- [ ] Implement database backups (automated daily)
- [ ] Encrypt database backups
- [ ] Test database restore procedure

### ✅ SQL Injection Prevention

⚠️ **Known Issue:** The codebase uses string concatenation for SQL queries.

**Immediate mitigations:**

- [ ] Review all user input points
- [ ] Implement input validation
- [ ] Use parameterized queries where possible
- [ ] Enable MySQL query logging to monitor suspicious queries
- [ ] Consider using a Web Application Firewall (WAF)

**Long-term solution:**

- [ ] Migrate to prepared statements (see ACTION_PLAN.md Phase 1)

---

## Application Security

### ✅ PHP Configuration

Edit `php.ini` or use `.htaccess`:

- [ ] Disable dangerous functions
  ```ini
  disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source
  ```
- [ ] Set expose_php to Off
  ```ini
  expose_php = Off
  ```
- [ ] Configure session security
  ```ini
  session.cookie_httponly = 1
  session.cookie_secure = 1  # Requires HTTPS
  session.use_strict_mode = 1
  ```
- [ ] Set upload limits
  ```ini
  upload_max_filesize = 10M
  post_max_size = 10M
  ```
- [ ] Enable open_basedir restriction
  ```ini
  open_basedir = /var/www/html
  ```

### ✅ Authentication & Sessions

- [ ] Enforce strong password policy (min 12 characters)
- [ ] Implement account lockout after failed attempts
- [ ] Set session timeout (currently no automatic logout)
- [ ] Implement secure password reset flow
- [ ] Add two-factor authentication (2FA) - requires development
- [ ] Log all authentication attempts
- [ ] Monitor for brute force attacks

### ✅ Cross-Site Scripting (XSS) Protection

⚠️ **Known Issue:** Limited XSS protection in legacy code.

- [ ] Review all user input/output points
- [ ] Ensure htmlspecialchars() is used consistently
- [ ] Implement Content Security Policy (CSP) headers
  ```
  Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'
  ```
- [ ] Sanitize all rich text editor content
- [ ] Validate file uploads (type, size, content)

### ✅ Cross-Site Request Forgery (CSRF) Protection

⚠️ **Critical Issue:** No CSRF protection visible in codebase.

**Immediate action:**

- [ ] Implement CSRF tokens for all forms
- [ ] Validate HTTP Referer header as temporary measure
- [ ] Add SameSite cookie attribute
  ```php
  session_set_cookie_params([
      'samesite' => 'Strict',
      'secure' => true,
      'httponly' => true
  ]);
  ```

**Example CSRF implementation:**

```php
// Generate token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// In form
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

// Validate
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('CSRF validation failed');
}
```

### ✅ File Upload Security

- [ ] Validate file types (whitelist approach)
- [ ] Scan uploaded files for malware
- [ ] Store files outside web root if possible
- [ ] Randomize uploaded filenames
- [ ] Set maximum file size limits
- [ ] Disable script execution in upload directory
  ```apache
  # In files/.htaccess
  php_flag engine off
  AddType text/plain .php .php3 .php4 .php5 .phtml
  ```

---

## Infrastructure Security

### ✅ Web Server (Apache)

- [ ] Disable directory listing
  ```apache
  Options -Indexes
  ```
- [ ] Hide Apache version
  ```apache
  ServerTokens Prod
  ServerSignature Off
  ```
- [ ] Implement security headers
  ```apache
  Header set X-Content-Type-Options "nosniff"
  Header set X-Frame-Options "SAMEORIGIN"
  Header set X-XSS-Protection "1; mode=block"
  Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  ```
- [ ] Enable mod_security (Web Application Firewall)
- [ ] Configure rate limiting
- [ ] Set up fail2ban for repeated attacks

### ✅ HTTPS/SSL Configuration

**Required for production:**

- [ ] Obtain SSL certificate (Let's Encrypt recommended)
- [ ] Configure Apache for HTTPS
- [ ] Redirect all HTTP to HTTPS
- [ ] Use strong cipher suites
- [ ] Enable HSTS (HTTP Strict Transport Security)
- [ ] Test with SSL Labs (https://www.ssllabs.com/ssltest/)

**Example Apache SSL configuration:**

```apache
<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem
    SSLCertificateChainFile /path/to/chain.pem

    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5
    SSLHonorCipherOrder on
</VirtualHost>
```

### ✅ Docker Security

- [ ] Don't run containers as root
- [ ] Use specific version tags (not `latest`)
- [ ] Scan images for vulnerabilities
- [ ] Limit container resources (CPU, memory)
- [ ] Use Docker secrets for passwords
- [ ] Enable Docker Content Trust
- [ ] Regularly update base images

---

## Monitoring & Logging

### ✅ Logging Configuration

- [ ] Enable application error logging
- [ ] Enable access logs
- [ ] Enable authentication logs
- [ ] Set up centralized logging (optional but recommended)
- [ ] Implement log rotation
- [ ] Monitor logs for suspicious activity

**What to log:**

- Failed login attempts
- File uploads
- Configuration changes
- Database errors
- API access (if implemented)
- Admin actions

### ✅ Security Monitoring

- [ ] Set up intrusion detection system (IDS)
- [ ] Monitor for unusual database queries
- [ ] Track failed authentication attempts
- [ ] Alert on multiple failures from same IP
- [ ] Monitor file system changes
- [ ] Set up uptime monitoring
- [ ] Configure security alerts (email/Slack)

### ✅ Audit Schedule

Create a security audit schedule:

**Daily:**

- [ ] Review authentication logs
- [ ] Check for failed login patterns
- [ ] Monitor system resources

**Weekly:**

- [ ] Review application logs
- [ ] Check for software updates
- [ ] Verify backup integrity
- [ ] Review user accounts

**Monthly:**

- [ ] Security patch review and application
- [ ] Password rotation (database, admin)
- [ ] Vulnerability scan
- [ ] Access control review
- [ ] SSL certificate expiry check

**Quarterly:**

- [ ] Full security audit
- [ ] Penetration testing
- [ ] Disaster recovery test
- [ ] Update security documentation

---

## Backup & Recovery

### ✅ Backup Strategy

- [ ] Automated daily database backups
- [ ] Automated weekly full backups
- [ ] Backup uploaded files separately
- [ ] Store backups off-site
- [ ] Encrypt backup files
- [ ] Test restore procedure monthly
- [ ] Document recovery steps

**Automated backup script example:**

```bash
#!/bin/bash
# Save as backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
docker exec db mysqldump -uroot -p${DB_PASS} dotproject | gzip > /backups/db_${DATE}.sql.gz
tar -czf /backups/files_${DATE}.tar.gz files/
# Upload to S3 or remote storage
aws s3 cp /backups/db_${DATE}.sql.gz s3://your-bucket/backups/
aws s3 cp /backups/files_${DATE}.tar.gz s3://your-bucket/backups/
# Delete local backups older than 7 days
find /backups -name "*.gz" -mtime +7 -delete
```

### ✅ Disaster Recovery

- [ ] Document recovery procedure
- [ ] Test recovery on separate environment
- [ ] Maintain offline copy of recovery documentation
- [ ] Define Recovery Time Objective (RTO)
- [ ] Define Recovery Point Objective (RPO)
- [ ] Assign recovery team roles

---

## Compliance & Legal

### ✅ Data Privacy

- [ ] Implement GDPR compliance (if applicable)
- [ ] Add privacy policy
- [ ] Add terms of service
- [ ] Implement user data export
- [ ] Implement user data deletion
- [ ] Log data access
- [ ] Encrypt personal data

### ✅ Access Control

- [ ] Implement principle of least privilege
- [ ] Regular access review
- [ ] Remove unused accounts
- [ ] Separate admin and user roles
- [ ] Document user permissions
- [ ] Implement role-based access control (RBAC)

---

## Known Vulnerabilities

### Critical Issues to Address

1. **SQL Injection** - Multiple endpoints vulnerable

   - Priority: CRITICAL
   - Timeline: Immediate
   - See: ACTION_PLAN.md Phase 1

2. **No CSRF Protection** - All forms vulnerable

   - Priority: CRITICAL
   - Timeline: Week 1
   - Implementation: Add token validation

3. **Outdated Dependencies** - Known CVEs in libraries

   - Priority: HIGH
   - Timeline: Month 1
   - Action: Update all dependencies

4. **Weak Session Management** - No timeout, weak cookies

   - Priority: HIGH
   - Timeline: Week 2
   - Action: Implement secure session config

5. **XSS Vulnerabilities** - Limited input sanitization

   - Priority: HIGH
   - Timeline: Month 1
   - Action: Implement comprehensive input/output encoding

6. **Information Disclosure** - Error messages expose system info
   - Priority: MEDIUM
   - Timeline: Week 1
   - Action: Disable error display, implement custom error pages

---

## Security Resources

### Tools for Security Testing

- **OWASP ZAP** - Web application security scanner
- **SQLMap** - SQL injection testing
- **Nikto** - Web server scanner
- **Burp Suite** - Comprehensive security testing
- **Snyk** - Dependency vulnerability scanning
- **PHPStan** - Static analysis for PHP

### Security Guides

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [Docker Security Best Practices](https://docs.docker.com/develop/security-best-practices/)
- [MySQL Security](https://dev.mysql.com/doc/refman/8.0/en/security.html)

---

## Security Incident Response

### If You Suspect a Breach

1. **Immediate Actions**

   - [ ] Take affected systems offline
   - [ ] Preserve logs and evidence
   - [ ] Change all passwords
   - [ ] Notify stakeholders
   - [ ] Document timeline of events

2. **Investigation**

   - [ ] Review access logs
   - [ ] Check for unauthorized accounts
   - [ ] Identify scope of breach
   - [ ] Determine attack vector
   - [ ] Document findings

3. **Remediation**

   - [ ] Patch vulnerabilities
   - [ ] Remove malicious code
   - [ ] Restore from clean backup
   - [ ] Strengthen security controls
   - [ ] Monitor for recurrence

4. **Post-Incident**
   - [ ] Conduct post-mortem
   - [ ] Update security policies
   - [ ] Implement additional controls
   - [ ] Train team on lessons learned
   - [ ] Consider external security audit

---

## Contact Information

**Security Issues:** Report to [security contact]  
**General Support:** [support contact]

**DO NOT** report security vulnerabilities in public GitHub issues.

---

## Checklist Summary

### Before Going Live

- [ ] All items in "Immediate Actions" completed
- [ ] HTTPS configured and tested
- [ ] Strong passwords everywhere
- [ ] File permissions set correctly
- [ ] Development tools disabled
- [ ] Monitoring and logging configured
- [ ] Backups automated and tested
- [ ] Security headers implemented
- [ ] WAF configured (optional but recommended)
- [ ] Security audit completed

### Regular Maintenance

- [ ] Daily log review
- [ ] Weekly updates check
- [ ] Monthly security patches
- [ ] Quarterly penetration test
- [ ] Annual full security audit

---

**Remember:** Security is an ongoing process, not a one-time task.

**Last Review:** November 11, 2025  
**Next Review:** December 11, 2025
