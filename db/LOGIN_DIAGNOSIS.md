# dotProject Login Process - Complete Diagnosis

## Login Flow (Step by Step)

### 1. Initial Request (`index.php` line 118)

```php
if (isset($_REQUEST['login'])) {
    $username = dPgetCleanParam($_POST, 'username', '');
    $password = dPgetParam($_POST, 'password', '');
    $ok = $AppUI->login($username, $password);
```

### 2. Login Method (`classes/ui.class.php` line 714)

The `login()` method performs these steps:

#### Step 2.1: Password Authentication

```php
$auth =& getauth($auth_method); // Default: 'sql'
if (!$auth->authenticate($username, $password)) {
    return false; // ❌ FAIL: Wrong password
}
$user_id = $auth->userId($username);
```

**Required Tables:**

- `dotp_users` - Must have record with `user_username='admin'` and `user_password='76a2173be639254e72ffa4d6df1030a'`

#### Step 2.2: ACL Permission Check (`classes/permissions.class.php` line 73)

```php
if (!($GLOBALS['acl']->checkLogin($user_id))) {
    dprint(__FILE__, __LINE__, 1, 'Permission check failed');
    return false; // ❌ FAIL: No ACL permissions
}
```

The `checkLogin()` method does:

```php
function checkLogin($login) {
    $q = new DBQuery;
    $q->addQuery('aro.value,aro.name, gr_aro.group_id');
    $q->addTable('gacl_aro', 'aro');
    $q->innerJoin('gacl_groups_aro_map', 'gr_aro', 'aro.id=gr_aro.aro_id');
    $q->addWhere('aro.value=' . (int)$login);
    $q->setLimit(1);
    $arr=$q->loadHash();
    return $arr?1:0; // Returns 1 if user belongs to ANY group, 0 otherwise
}
```

**This is THE CRITICAL CHECK!** It verifies:

- User exists in `dotp_gacl_aro` table
- User is mapped to at least one group in `dotp_gacl_groups_aro_map`

#### Step 2.3: Load User Information

```php
$q->addTable('users');
$q->addQuery('user_id, contact_first_name, contact_last_name...');
$q->addJoin('contacts', 'con', 'contact_id = user_contact');
$q->addWhere("user_id = $user_id AND user_username = '$username'");
```

**Required Tables:**

- `dotp_users` joined with `dotp_contacts`

## Required Database Records for Successful Login

### Table 1: `dotp_users`

```sql
SELECT user_id, user_username, user_password, user_contact
FROM dotp_users
WHERE user_username='admin';
```

**Expected:**
| user_id | user_username | user_password | user_contact |
|---------|---------------|---------------|--------------|
| 1 | admin | 76a2173be639254e72ffa4d6df1030a | 1 |

### Table 2: `dotp_contacts`

```sql
SELECT contact_id, contact_first_name, contact_last_name, contact_email
FROM dotp_contacts
WHERE contact_id=1;
```

**Expected:**
| contact_id | contact_first_name | contact_last_name | contact_email |
|------------|-------------------|-------------------|---------------|
| 1 | Admin | User | admin@example.com |

### Table 3: `dotp_gacl_aro` (Access Request Object - User)

```sql
SELECT id, section_value, value, order_value, name, hidden
FROM dotp_gacl_aro
WHERE value='1';
```

**Expected:**
| id | section_value | value | order_value | name | hidden |
|----|---------------|-------|-------------|------|--------|
| 1 | user | 1 | 1 | Administrator | 0 |

**Purpose:** Represents the admin user in the ACL system

### Table 4: `dotp_gacl_aro_groups` (User Groups)

```sql
SELECT id, parent_id, lft, rgt, name, value
FROM dotp_gacl_aro_groups
WHERE value='admin';
```

**Expected:**
| id | parent_id | lft | rgt | name | value |
|----|-----------|-----|-----|------|-------|
| 1 | 0 | 1 | 2 | Administrator | admin |

**Purpose:** Defines the admin group

### Table 5: `dotp_gacl_groups_aro_map` ⚠️ **CRITICAL FOR LOGIN**

```sql
SELECT group_id, aro_id
FROM dotp_gacl_groups_aro_map
WHERE aro_id=1;
```

**Expected:**
| group_id | aro_id |
|----------|--------|
| 1 | 1 |

**Purpose:** Maps user (aro_id=1) to admin group (group_id=1)
**THIS IS THE MOST IMPORTANT RECORD - WITHOUT IT, LOGIN FAILS!**

### Table 6: `dotp_gacl_aro_map` (Optional but recommended)

```sql
SELECT acl_id, section_value, value
FROM dotp_gacl_aro_map
WHERE value='1';
```

**Expected:**
| acl_id | section_value | value |
|--------|---------------|-------|
| 1 | user | 1 |

**Purpose:** Maps user to specific ACL rules

### Table 7: `dotp_dotpermissions` (Module Permissions)

```sql
SELECT acl_id, user_id, section, axo, permission, allow
FROM dotp_dotpermissions
WHERE user_id=1
LIMIT 10;
```

**Expected:** Multiple rows granting permissions like:
| acl_id | user_id | section | axo | permission | allow |
|--------|---------|---------|-----|------------|-------|
| 1 | 1 | app | admin | access | 1 |
| 1 | 1 | app | admin | view | 1 |
| 1 | 1 | app | companies | access | 1 |
| ... | ... | ... | ... | ... | ... |

**Purpose:** Grants module-level permissions after login succeeds

## Common Failure Points

### Failure 1: "Login Failed" - Wrong Password

**Symptom:** Message "Login Failed"
**Cause:** Password hash doesn't match
**Fix:** Verify password hash in `dotp_users.user_password`

### Failure 2: "Login Failed" - Permission Check Failed

**Symptom:** Message "Login Failed" but password is correct
**Cause:** `checkLogin()` returns 0
**Root Cause:** User not mapped to any group in `dotp_gacl_groups_aro_map`
**Fix:** Run the fix_acl_mapping.sql script

### Failure 3: Login Succeeds but No Module Access

**Symptom:** Login works but no modules visible
**Cause:** `dotp_dotpermissions` table is empty
**Fix:** Run `regeneratePermissions()` or populate manually

## Verification Queries

Run these queries to diagnose the current state:

```sql
-- 1. Check if admin user exists
SELECT 'Admin User' as Check_Item, user_id, user_username, user_contact
FROM dotp_users
WHERE user_username='admin';

-- 2. Check if admin contact exists
SELECT 'Admin Contact' as Check_Item, contact_id, contact_first_name, contact_last_name
FROM dotp_contacts
WHERE contact_id=1;

-- 3. Check if ACL user object exists
SELECT 'ACL User (ARO)' as Check_Item, id, value, name
FROM dotp_gacl_aro
WHERE value='1';

-- 4. Check if admin group exists
SELECT 'ACL Admin Group' as Check_Item, id, name, value
FROM dotp_gacl_aro_groups
WHERE value='admin';

-- 5. ⚠️ CRITICAL: Check if user is mapped to group
SELECT 'User-to-Group Mapping (CRITICAL)' as Check_Item, group_id, aro_id
FROM dotp_gacl_groups_aro_map
WHERE aro_id=1;

-- 6. Check ARO map (optional but helpful)
SELECT 'ARO Map' as Check_Item, acl_id, section_value, value
FROM dotp_gacl_aro_map
WHERE value='1';

-- 7. Check permissions count
SELECT 'Permissions Count' as Check_Item, COUNT(*) as count
FROM dotp_dotpermissions
WHERE user_id=1;
```

## The Fix

If any of the above checks fail, run:

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/fix_acl_mapping.sql
```

Then verify with the queries above.

## Why Login is Failing Now

Based on the conversation history, the most likely cause is:

- **Table `dotp_gacl_groups_aro_map` is EMPTY**

This table is the bridge between users and groups. Without a record mapping user ID 1 to group ID 1, the `checkLogin()` function returns 0, causing authentication to fail even with the correct password.

The `fix_acl_mapping.sql` script specifically addresses this by inserting:

```sql
INSERT IGNORE INTO dotp_gacl_groups_aro_map (group_id, aro_id)
VALUES (1, 1);
```

This single record is what makes the difference between login failure and success.
