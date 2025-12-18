# Fuzzy Plugin

## Description
Enables fuzzy matching as an option on searches.

## Examples
- `SELECT * FROM table1 WHERE MATCH('abc') OPTION fuzzy=1, preserve=1, layouts='us,ua', distance=2;`
- `SELECT * FROM table1 WHERE MATCH('abc') OPTION fuzzy=1 AND (category='cat1');`
- `/search -d '{"table": "table1", "query": {"bool": {"must": [ { "match": {"*": "abc"} } ] } }, "options": {"fuzzy": true, "layouts": ["us", "ru"], "distance": 2} }'`
