# DistributedInsert Plugin

## Description
Enables inserts/bulk writes into distributed tables.

## Examples
- `INSERT INTO distr_table1(f) values('abc');`
- `UPDATE distr_table1 SET f='abc';`
- `REPLACE INTO distr_table1(f) values('abc');`
- `DELETE FROM distr_table1(f) f='abc';`
- `/sql -d "INSERT INTO distr_table1(f) values('abc')"`
- `/cli -d "INSERT INTO distr_table1(f) values('abc')"`
