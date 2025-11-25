# Auth Plugin

The Auth plugin handles authentication and authorization commands for Manticoresearch Buddy, including user management, permissions, and password changes. It parses SQL-like queries that Manticore doesn't natively support and routes them to appropriate handlers.

## Supported Queries

- `CREATE USER 'username' IDENTIFIED BY 'password'`
- `DROP USER 'username'`
- `GRANT <action> ON <target> TO 'username' [WITH BUDGET <json>]` (actions: read, write, schema, admin, replication; target: '*' or table name)
- `REVOKE <action> ON <target> FROM 'username'`
- `SHOW MY PERMISSIONS`
- `SET PASSWORD 'newpass' [FOR 'username']`