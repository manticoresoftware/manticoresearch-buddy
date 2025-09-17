# Knn Plugin

## Description
Enables KNN by document id.

## Examples
- `SELECT * FROM table WHERE knn(query_vector, 5, 123);`
- `SELECT * FROM table WHERE knn(query_vector, 10, 456) AND category = 'tech';`
- JSON: `{"table":"table","knn":{"field":"vector","k":5,"doc_id":123}}`