# Insert Plugin

## Description
Supports Auto-schema on write assisting with inserts into not-yet-existing tables.

## Examples

- `INSERT INTO new_table(f) VALUES(1)`
- `/insert -d '{"table": "new_table", "id": 1, "doc": {"f": 1}}`
- `/new_table/_create/1 -d '{"f": 1}`
- `/new_table/_create/ -d '{"f": 1}`
- `/_bulk -H 'Content-Type: application/x-ndjson' -d '{ "index" : { "table" : "new_table" } }\n{ "f": 1 }\n'`
