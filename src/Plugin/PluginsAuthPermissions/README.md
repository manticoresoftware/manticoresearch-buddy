# Plugins Auth Permissions

The `PluginsAuthPermissions` plugin handles failed `GRANT` and `REVOKE`
statements for plugin-managed resources. It lets users grant permissions with
resource names, while Buddy converts those requests to the internal system
tables used by plugins.

The plugin runs only as a fallback when Manticore returns an error for a
`GRANT` or `REVOKE` query that targets a supported plugin resource.

## Internal Buddy Requests

`PluginsAuthPermissions` only morphs user-facing permission queries. It does
not make plugin runtime requests run as `system.buddy`.

Plugin code that must access internal system tables on behalf of Buddy should
use the plugin-side system client helper, for example Queue's
`InternalBuddyClientTrait::getSystemClient()`. That helper clones the current
Manticore client and sets the delegated user to `system.buddy`, so internal
operations such as Queue buffer table reads/writes are executed as Buddy while
the original user request still keeps its own identity.

## Supported Resources

| Resource | Internal table |
|---|---|
| `source/name` | `system.source_name` |
| `mva/name` | `system.materialized_view_name` |
| `materialized view/name` | `system.materialized_view_name` |
| `chat_model/name` | `system.chat_model_name` |
| `chat model/name` | `system.chat_model_name` |

`mva` and `materialized view` are aliases for materialized views. They always
map to `system.materialized_view_*`.

Wildcard names are supported:

```sql
GRANT READ ON source/* TO user;
GRANT READ ON mva/* TO user;
GRANT READ ON materialized view/* TO user;
GRANT READ ON chat_model/* TO user;
GRANT READ ON chat model/* TO user;
```

These are morphed to:

```sql
GRANT READ ON 'system.source_*' TO user;
GRANT READ ON 'system.materialized_view_*' TO user;
GRANT READ ON 'system.materialized_view_*' TO user;
GRANT READ ON 'system.chat_model_*' TO user;
GRANT READ ON 'system.chat_model_*' TO user;
```

## Chat Model History

Chat models require access to two internal tables:

- `system.chat_model_name`
- `system.chat_history_name`

For this reason, a single `chat_model/name` permission query executes two
internal permission queries.

For example:

```sql
GRANT READ ON chat_model/assistant TO alice;
```

is executed as:

```sql
GRANT READ ON 'system.chat_model_assistant' TO alice;
GRANT READ ON 'system.chat_history_assistant' TO alice;
```

The same applies to `REVOKE`.

The spaced resource form is equivalent:

```sql
GRANT READ ON chat model/assistant TO alice;
```

## Examples

Grant access to a source:

```sql
GRANT READ ON source/orders TO alice;
```

Grant access to all materialized views:

```sql
GRANT READ ON mva/* TO alice;
```

Grant access to a materialized view using the full resource name:

```sql
GRANT READ ON materialized view/daily_sales TO alice;
```

Revoke access to a chat model and its history:

```sql
REVOKE READ ON chat_model/support_assistant FROM alice;
```

## Resource Names

Resource names must be valid identifiers:

```text
[A-Za-z_][A-Za-z0-9_]*
```

The only non-identifier value accepted as a resource name is `*` for wildcard
permissions.
