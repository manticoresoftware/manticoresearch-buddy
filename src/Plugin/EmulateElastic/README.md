# EmulateElastic Plugin

## Description
Implemenents Elasticsearch-like API endpoints emulation. 

## Examples
- `/_bulk '{ "index": { "table" : "table1", "_id" : "1" } }\n{ "f" : "abc"}\n`
- `/table1/_search -d {"query": {"match_all": {}} }`
