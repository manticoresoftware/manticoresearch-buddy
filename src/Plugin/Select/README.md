# Select Plugin

## Description
Handles SELECT queries used specifically by different MySQL tools and not supported by Manticore.

## Examples
- `SELECT * FROM information_schema.columns`
- `SELECT t.* FROM information_schema.schemata as t`
- `SELECT t.f1 AS f2 FROM Manticore.table1`
