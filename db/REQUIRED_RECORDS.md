# Required Database Records for Admin Login

## Quick Summary

For admin login to work in dotProject, these 5 tables MUST have specific records:

1. ✅ **dotp_users** - Admin user account
2. ✅ **dotp_contacts** - Admin contact info
3. ✅ **dotp_gacl_aro** - ACL user object
4. ✅ **dotp_gacl_aro_groups** - ACL admin group
5. ⚠️ **dotp_gacl_groups_aro_map** - **USER-TO-GROUP MAPPING (CRITICAL!)**

## Detailed Requirements

### Table 1: dotp_users

**Purpose:** Stores user accounts
**Required Record:**

```sql
INSERT INTO dotp_users (user_id, user_contact, user_username, user_password, user_type)
VALUES (1, 1, 'admin', '76a2173be639254e72ffa4d6df1030a', 1);
```

| Column        | Value                           | Notes                  |
| ------------- | ------------------------------- | ---------------------- |
| user_id       | 1                               | Primary key            |
| user_contact  | 1                               | Links to dotp_contacts |
| user_username | admin                           | Login username         |
| user_password | 76a2173be639254e72ffa4d6df1030a | MD5 hash of "passwd"   |
| user_type     | 1                               | Admin user type        |

---

### Table 2: dotp_contacts

**Purpose:** Stores contact information
**Required Record:**

```sql
INSERT INTO dotp_contacts (contact_id, contact_first_name, contact_last_name, contact_email)
VALUES (1, 'Admin', 'User', 'admin@example.com');
```

| Column             | Value             | Notes         |
| ------------------ | ----------------- | ------------- |
| contact_id         | 1                 | Primary key   |
| contact_first_name | Admin             | First name    |
| contact_last_name  | User              | Last name     |
| contact_email      | admin@example.com | Email address |

---

### Table 3: dotp_gacl_aro

**Purpose:** Access Request Objects - represents users in ACL system
**Required Record:**

```sql
INSERT INTO dotp_gacl_aro (id, section_value, value, order_value, name, hidden)
VALUES (1, 'user', '1', 1, 'Administrator', 0);
```

| Column        | Value         | Notes                     |
| ------------- | ------------- | ------------------------- |
| id            | 1             | Primary key               |
| section_value | user          | Object type               |
| value         | 1             | User ID (matches user_id) |
| order_value   | 1             | Display order             |
| name          | Administrator | Display name              |
| hidden        | 0             | Not hidden                |

**This represents the admin user in the ACL permission system.**

---

### Table 4: dotp_gacl_aro_groups

**Purpose:** Groups for Access Request Objects
**Required Record:**

```sql
INSERT INTO dotp_gacl_aro_groups (id, parent_id, lft, rgt, name, value)
VALUES (1, 0, 1, 2, 'Administrator', 'admin');
```

| Column    | Value         | Notes                  |
| --------- | ------------- | ---------------------- |
| id        | 1             | Primary key            |
| parent_id | 0             | No parent (top level)  |
| lft       | 1             | Nested set left value  |
| rgt       | 2             | Nested set right value |
| name      | Administrator | Display name           |
| value     | admin         | Internal identifier    |

**This defines the admin group.**

---

### Table 5: dotp_gacl_groups_aro_map ⚠️ CRITICAL!

**Purpose:** Maps users (AROs) to groups
**Required Record:**

```sql
INSERT INTO dotp_gacl_groups_aro_map (group_id, aro_id)
VALUES (1, 1);
```

| Column   | Value | Notes                              |
| -------- | ----- | ---------------------------------- |
| group_id | 1     | References dotp_gacl_aro_groups.id |
| aro_id   | 1     | References dotp_gacl_aro.id        |

**⚠️ THIS IS THE MOST CRITICAL RECORD!**

Without this record, the `checkLogin()` function returns 0, causing login to fail even with correct password.

This record means: "User with ARO ID 1 belongs to Group ID 1 (admin group)"

---

## Optional but Recommended Tables

### Table 6: dotp_gacl_aro_map

**Purpose:** Maps AROs to specific ACLs
**Recommended Record:**

```sql
INSERT INTO dotp_gacl_aro_map (acl_id, section_value, value)
VALUES (1, 'user', '1');
```

---

### Table 7: dotp_dotpermissions

**Purpose:** Flattened permissions table for performance
**Populated by:** `regeneratePermissions()` function
**Sample Records:**

```sql
INSERT INTO dotp_dotpermissions (acl_id, user_id, section, axo, permission, allow, priority, enabled)
VALUES
(1, 1, 'app', 'admin', 'access', 1, 1, 1),
(1, 1, 'app', 'admin', 'view', 1, 1, 1),
(1, 1, 'app', 'admin', 'edit', 1, 1, 1),
(1, 1, 'app', 'companies', 'access', 1, 1, 1),
(1, 1, 'app', 'companies', 'view', 1, 1, 1);
```

Without records in this table, login may succeed but user will have no module access.

---

## The Login Process Flow

```
1. User submits form with username="admin", password="passwd"
   ↓
2. Password check: MD5(passwd) == user_password?
   ├─ No → Login Failed ❌
   └─ Yes → Continue to step 3
   ↓
3. ACL check: Does user belong to any group?
   Query: SELECT from dotp_gacl_aro
          INNER JOIN dotp_gacl_groups_aro_map
          WHERE aro.value=1
   ├─ No records found → Login Failed ❌ (THIS IS WHERE IT'S FAILING!)
   └─ Record found → Continue to step 4
   ↓
4. Load user data from dotp_users + dotp_contacts
   ├─ Not found → Login Failed ❌
   └─ Found → Login Success ✅
   ↓
5. Check dotp_dotpermissions for module access
   ├─ Empty → Login succeeds but no modules visible ⚠️
   └─ Has records → Full access ✅
```

---

## How to Fix

### Step 1: Run Verification

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/verify_login_requirements.sql
```

### Step 2: If verification shows missing mapping, run fix

```bash
docker exec -i db mysql -uroot -pA_1234567 dotproject < db/fix_acl_mapping.sql
```

### Step 3: Try logging in

- URL: http://localhost:8000
- Username: admin
- Password: passwd

---

## Why This Is Complex

dotProject uses phpGACL (PHP Generic Access Control List) which implements a sophisticated multi-table permission system:

- **ARO** (Access Request Object) = Who is requesting access (users)
- **AXO** (Access Controlled Object) = What is being accessed (modules, records)
- **ACO** (Access Control Object) = What action is being performed (view, edit, delete)
- **Groups** = Collections of AROs or AXOs
- **ACL** (Access Control List) = Rules connecting AROs, ACOs, and AXOs

The mapping tables connect everything together. Without the mapping in `dotp_gacl_groups_aro_map`, the user exists in the system but has no group membership, which the `checkLogin()` function interprets as "not allowed to log in."

---

## Summary

**The single most important record for login to work:**

```sql
INSERT INTO dotp_gacl_groups_aro_map (group_id, aro_id) VALUES (1, 1);
```

This one row in this one table is the difference between login working and not working.
