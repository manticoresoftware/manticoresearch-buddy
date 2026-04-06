# RAG in Manticore Buddy

This document describes the current SQL contract of the `ConversationalRag` plugin.
It is aligned with:

- Buddy implementation in `src/Plugin/ConversationalRag/*`
- the `llm` PHP extension API
- the CLT flow used for the basic conversational RAG scenario

## Supported Commands

- `CREATE RAG MODEL`
- `SHOW RAG MODELS`
- `DESCRIBE RAG MODEL`
- `DROP RAG MODEL`
- `CALL CONVERSATIONAL_RAG`

## LLM Configuration

The plugin uses the `llm` PHP extension and constructs the client as:

```php
new Llm(string $model, array $options = [])
```

Documented extension options include:

- `api_key`
- `base_url`
- `timeout`

The extension README also documents multiple providers, including:

- `openai`
- `anthropic`
- `openrouter`
- `vertex`
- `bedrock`
- `workers-ai`
- `deepseek`
- `zai`

Buddy behavior:

- Buddy-specific settings `retrieval_limit` and `max_document_length` are handled by Buddy itself
- response tuning is internal, and final answers are capped at 4096 tokens
- only `api_key`, `base_url`, and `timeout` are forwarded to the extension constructor options

This means `settings.base_url` is the correct place to configure an OpenAI-compatible proxy.

## CREATE RAG MODEL

Minimal example:

```sql
CREATE RAG MODEL test_assistant (
    model='openai:gpt-4o-mini'
);
```

Proxy example:

```sql
CREATE RAG MODEL proxy_assistant (
    model='openai:gpt-4o-mini',
    api_key='proxy-key',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=3000
);
```

Required fields:

- `model` in `provider:model` format

Recommended optional fields:

- `description`
- `style_prompt`
- `api_key`
- `base_url`
- `timeout`
- `retrieval_limit`
- `max_document_length`

`name` inside the body is accepted as a legacy alias for `description`. The model identifier always comes from `CREATE RAG MODEL <identifier>`.

`max_document_length` is normalized during model creation:

- valid values are `-1` or any positive integer
- missing or invalid values are stored as the default `2000`

## Model Format

Recommended form:

```sql
model='openai:gpt-4o-mini'
```

Examples here use `openai`, but provider support comes from the installed `llm` extension, not from a hardcoded Buddy whitelist.

## SHOW / DESCRIBE / DROP

List models:

```sql
SHOW RAG MODELS;
```

Describe model:

```sql
DESCRIBE RAG MODEL test_assistant;
```

`DESCRIBE` flattens settings as `settings.<key>`, for example:

- `settings.api_key`
- `settings.base_url`
- `settings.timeout`
- `settings.retrieval_limit`
- `settings.max_document_length`

Drop model:

```sql
DROP RAG MODEL test_assistant;
DROP RAG MODEL IF EXISTS test_assistant;
```

## CALL CONVERSATIONAL_RAG

Syntax:

```sql
CALL CONVERSATIONAL_RAG(
    'query',
    'table_name',
    'model_name_or_uuid',
    'content_fields',
    'optional_conversation_uuid'
);
```

Parameters:

| Position | Parameter | Required | Description |
|---:|---|---:|---|
| 1 | `query` | Yes | User query |
| 2 | `table` | Yes | Search table |
| 3 | `model_name_or_uuid` | Yes | Model name or UUID |
| 4 | `content_fields` | Yes | Comma-separated fields used to build context |
| 5 | `conversation_uuid` | No | If omitted, Buddy generates one |

Example:

```sql
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'docs',
    'test_assistant',
    'content'
);
```

Multiple content fields:

```sql
CALL CONVERSATIONAL_RAG(
    'Tell me about RAG',
    'docs',
    'test_assistant',
    'title,content'
);
```

## Data Requirements

The search table must have:

- at least one `FLOAT_VECTOR` field
- one or more text fields referenced by `content_fields`

Example:

```sql
CREATE TABLE docs (
    id BIGINT,
    content TEXT,
    title TEXT,
    embedding_vector FLOAT_VECTOR
        knn_type='hnsw'
        hnsw_similarity='cosine'
        model_name='sentence-transformers/all-MiniLM-L6-v2'
        from='content,title'
) TYPE='rt';
```

If the embedding model is remote, configure its API key on the table definition itself, for example:

```sql
api_key='${OPENAI_API_KEY}'
```

## Recommended Production Pattern

Use flat top-level options for extension transport options and Buddy search controls:

```sql
CREATE RAG MODEL assistant (
    model='openai:gpt-4o-mini',
    style_prompt='You answer only from the provided context.',
    api_key='proxy-key',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=-1
);
```

`max_document_length` controls how much text from each matched document is packed into the final prompt:

- use a smaller value to reduce cost and latency
- use a larger value to preserve more context
- use `-1` to disable truncation entirely when quality matters more than spend
