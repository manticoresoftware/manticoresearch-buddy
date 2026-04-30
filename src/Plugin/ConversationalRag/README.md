# ConversationalRag Plugin

The `ConversationalRag` plugin adds SQL-managed conversational RAG to Manticore Buddy.
It combines:

- vector search over a table with `FLOAT_VECTOR` fields
- conversation history tracking
- LLM-based intent classification and response generation
- the `llm` PHP extension for provider access

This document is based on the current plugin implementation in Buddy, the `llm-php-ext` API, and the CLT coverage used for the basic conversational RAG flow.

## What Is Supported

Supported SQL commands:

- `CREATE RAG MODEL`
- `SHOW RAG MODELS`
- `DESCRIBE RAG MODEL`
- `DROP RAG MODEL`
- `CALL CONVERSATIONAL_RAG`

Supported model storage and runtime behavior:

- `model` is required and must use `provider:model` format
- `style_prompt` is optional
- Buddy-specific setting:
  - `retrieval_limit`
- `max_document_length` controls per-document context truncation
- response tuning is internal to the plugin; final answers are capped at 4096 tokens
- extension-level transport options are passed through from model settings to `new Llm($model, $options)`
  - supported keys are `api_key`, `base_url`, and `timeout`

## Quick Start

### 1. Prepare a table with embeddings

The RAG query searches an existing table and expects at least:

- one `FLOAT_VECTOR` field
- auto-embedding source fields configured on that vector field with `from='...'`

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

If your embedding model requires a remote API key, configure that on the table itself. The CLT flow uses:

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
        api_key='${OPENAI_API_KEY}'
) TYPE='rt';
```

### 2. Create a RAG model

Minimal model:

```sql
CREATE RAG MODEL test_assistant (
    model='openai:gpt-4o-mini'
);
```

Model with proxy and custom LLM options:

```sql
CREATE RAG MODEL proxy_assistant (
    model='openai:gpt-4o-mini',
    style_prompt='You are a helpful assistant specializing in search technology',
    api_key='sk-proxy-or-openai-key',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=3000
);
```

### 3. Ask a question

```sql
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'docs',
    'test_assistant'
);
```

With an explicit conversation UUID:

```sql
CALL CONVERSATIONAL_RAG(
    'Can you explain more?',
    'docs',
    'test_assistant',
    'test-conv-12345678-1234-1234-1234-123456789abc'
);
```

## How LLM Configuration Works

The plugin uses the `llm` PHP extension.

The extension constructor is:

```php
new Llm(string $model, array $options = [])
```

Documented extension options include:

- `api_key`
- `base_url`
- `timeout`

The extension README documents support for multiple providers, including:

- `openai`
- `anthropic`
- `openrouter`
- `vertex`
- `bedrock`
- `workers-ai`
- `deepseek`
- `zai`

Buddy passes model options to the extension like this:

1. It loads model `settings`
2. It extracts Buddy-managed fields such as `retrieval_limit`
3. It forwards the remaining settings to the extension constructor

### API key

You can provide the API key in either of these ways:

- via the extension's environment variable support, such as `OPENAI_API_KEY`
- via model `api_key`

Example:

```sql
CREATE RAG MODEL api_key_assistant (
    model='openai:gpt-4o-mini',
    api_key='sk-...'
);
```

### Custom base URL

Use `base_url`.

Example for an OpenAI-compatible proxy:

```sql
CREATE RAG MODEL local_proxy_assistant (
    model='openai:gpt-4o-mini',
    api_key='local-proxy-key',
    base_url='http://host.docker.internal:8787/v1'
);
```

### Timeout

Use `timeout`.

Example:

```sql
CREATE RAG MODEL slow_backend_assistant (
    model='openai:gpt-4o-mini',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60
);
```

## Model Identifier Format

Buddy stores the full model id from `model` and validates `provider:model` format on create.

Supported pattern:

```sql
model='openai:gpt-4o-mini'
```

Examples in this document use `openai`, but provider support is defined by the installed `llm` extension rather than by a hardcoded provider whitelist in Buddy.

## CREATE RAG MODEL

### Required fields

- `model`

### Common optional fields

- `description`
- `style_prompt`
- `api_key`
- `base_url`
- `timeout`
- `retrieval_limit`
- `max_document_length`

The model identifier always comes from `CREATE RAG MODEL <identifier>`. Use `description` for descriptive text inside the body. `name` inside the body is not supported.

```sql
CREATE RAG MODEL advanced_assistant (
    model='openai:gpt-4o',
    style_prompt='You are a helpful assistant specializing in search technology',
    api_key='sk-...',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=3000
);
```

Validation enforced by Buddy:

- `retrieval_limit`: `1..50`
- `max_document_length`: `0` disables truncation; otherwise `100..65536`

### Returned result

`CREATE RAG MODEL` returns one row with one column:

- `uuid`

## SHOW RAG MODELS

```sql
SHOW RAG MODELS;
```

Returned columns:

- `uuid`
- `name`
- `model`
- `created_at`

## DESCRIBE RAG MODEL

By name:

```sql
DESCRIBE RAG MODEL test_assistant;
```

By UUID:

```sql
DESCRIBE RAG MODEL '550e8400-e29b-41d4-a716-446655440000';
```

Returned columns:

- `property`
- `value`

Settings are flattened as:

- `settings.retrieval_limit`
- `settings.max_document_length`
- `settings.base_url`
- `settings.timeout`
- `settings.api_key`

## DROP RAG MODEL

By name:

```sql
DROP RAG MODEL test_assistant;
```

By UUID:

```sql
DROP RAG MODEL '550e8400-e29b-41d4-a716-446655440000';
```

Safe delete:

```sql
DROP RAG MODEL IF EXISTS test_assistant;
```

## CALL CONVERSATIONAL_RAG

Syntax:

```sql
CALL CONVERSATIONAL_RAG(
    'user query',
    'table_name',
    'model_name_or_uuid',
    'optional_conversation_uuid',
    'optional_fields'
);
```

Positional parameters:

| Position | Parameter | Required | Description |
|---:|---|---:|---|
| 1 | `query` | Yes | User question |
| 2 | `table` | Yes | Table to search |
| 3 | `model_name_or_uuid` | Yes | RAG model name or UUID |
| 4 | `conversation_uuid` | No | 4th positional argument. Pass `''` when you want to set `fields` without a conversation id |
| 5 | `fields` | No | 5th positional argument. Comma-separated fields used to build context |

`CALL CONVERSATIONAL_RAG` supports positional arguments only.

```sql
CALL CONVERSATIONAL_RAG(
    'user query',
    'table_name',
    'model_name_or_uuid',
    '',
    'title,content'
);
```

Notes:

- when `fields` is provided, those fields are used for context construction
- otherwise context fields are auto-detected from the `from='...'` setting of the detected `FLOAT_VECTOR` field
- missing fields are skipped and logged as warnings
- empty or whitespace-only field values are skipped
- multiple fields are joined with `, `

## Response Format

`CALL CONVERSATIONAL_RAG` returns:

- `conversation_uuid`
- `user_query`
- `search_query`
- `response`
- `sources`

Example shape:

```json
{
  "conversation_uuid": "generated-or-provided-uuid",
  "user_query": "What is vector search?",
  "search_query": "vector search, embeddings, similarity search",
  "response": "AI-generated response text",
  "sources": "[{\"id\":1,\"title\":\"Doc Title\",\"content\":\"...\"}]"
}
```

## Search and Context Behavior

Conversation flow includes:

- LLM-based intent classification
- KNN search over a detected `FLOAT_VECTOR` field
- optional exclusion of previously rejected or already shown items
- context building from explicit `fields` (when provided) or auto-detected embedding source fields
- context truncation using `max_document_length`

Buddy-specific search settings:

- `retrieval_limit`: number of documents retrieved for KNN
- `max_document_length`: per-document context budget; `0` disables truncation

`max_document_length` directly affects result quality and cost:

- smaller values reduce prompt size and spend, but can hide useful context
- larger values improve context completeness, but increase token usage
- `0` disables truncation entirely and should be used carefully with large documents

## Tested Examples

The basic end-to-end flow covered by CLT includes:

1. create a vectorized `docs` table
2. insert documents
3. create a minimal RAG model
4. create an advanced RAG model with transport options
5. run `SHOW RAG MODELS`
6. run `DESCRIBE RAG MODEL`
7. run `CALL CONVERSATIONAL_RAG`
8. drop the models

Representative examples from that flow:

```sql
CREATE RAG MODEL test_assistant (
    model='openai:gpt-4o-mini'
);
```

```sql
CREATE RAG MODEL advanced_assistant (
    model='openai:gpt-4o',
    style_prompt='You are a helpful assistant specializing in search technology',
    api_key='proxy-key',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=3000
);
```

```sql
CALL CONVERSATIONAL_RAG('suggest me good CPU','docs_merged','test_assistant','c1', 'embedding_content,embedding_brand');
```

## Recommended Usage

For production usage with an OpenAI-compatible proxy, prefer this pattern:

```sql
CREATE RAG MODEL assistant (
    model='openai:gpt-4o-mini',
    style_prompt='You answer only from the provided context.',
    api_key='proxy-key',
    base_url='http://host.docker.internal:8787/v1',
    timeout=60,
    retrieval_limit=5,
    max_document_length=0
);
```

This keeps:

- provider transport settings in `settings`
- Buddy search controls in `settings`
- prompt behavior in `style_prompt`
