# Autocomplete Plugin

## Description
Implements query suggestions / prefix+infix completion for search.

## Examples
- `CALL AUTOCOMPLETE('some_query', 'table1');`
- CALL AUTOCOMPLETE('some_query', 'table1', 0 as fuzziness);
- CALL AUTOCOMPLETE('some_query', 'table1', 1 as preserve);
- `/autocomplete -d '{"query": "some_query", "table": "table1"}'`
- `/autocomplete -d '{"query": "some_query", "table": "table1", "options": {"preserve": 1} }'`
