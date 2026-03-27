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

- `llm_provider` and `llm_model` are required
- `style_prompt` is optional
- Buddy-specific settings:
  - `k_results`
  - `similarity_threshold`
  - `max_document_length`
- completion settings handled directly by Buddy:
  - `temperature`
  - `max_tokens`
  - `top_p`
  - `frequency_penalty`
  - `presence_penalty`
- extension-level transport options are passed through from model settings to `new Llm($model, $options)`
  - supported keys are `api_key`, `base_url`, and `timeout`

## Quick Start

### 1. Prepare a table with embeddings

The RAG query searches an existing table and expects at least:

- one `FLOAT_VECTOR` field
- one or more text fields that you will pass as `content_fields`

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
    llm_provider='openai',
    llm_model='gpt-4o-mini'
);
```

Model with proxy and custom LLM options:

```sql
CREATE RAG MODEL proxy_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    style_prompt='You are a helpful assistant specializing in search technology',
    settings='{
        "api_key":"sk-proxy-or-openai-key",
        "base_url":"http://host.docker.internal:8787/v1",
        "timeout":60,
        "temperature":0.3,
        "max_tokens":2000,
        "k_results":5
    }'
);
```

### 3. Ask a question

```sql
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'docs',
    'test_assistant',
    'content'
);
```

With multiple content fields:

```sql
CALL CONVERSATIONAL_RAG(
    'Tell me about RAG',
    'docs',
    'test_assistant',
    'title,content'
);
```

With an explicit conversation UUID:

```sql
CALL CONVERSATIONAL_RAG(
    'Can you explain more?',
    'docs',
    'test_assistant',
    'content',
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
2. It extracts Buddy-managed fields such as `k_results`
3. It forwards the remaining settings to the extension constructor

That means the recommended place for provider-specific transport options is `settings`.

### API key

You can provide the API key in either of these ways:

- via the extension's environment variable support, such as `OPENAI_API_KEY`
- via model `settings.api_key`

Example:

```sql
CREATE RAG MODEL api_key_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    settings='{"api_key":"sk-..."}'
);
```

### Custom base URL

Use `settings.base_url`.

Example for an OpenAI-compatible proxy:

```sql
CREATE RAG MODEL local_proxy_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    settings='{
        "api_key":"local-proxy-key",
        "base_url":"http://host.docker.internal:8787/v1"
    }'
);
```

### Timeout

Use `settings.timeout`.

Example:

```sql
CREATE RAG MODEL slow_backend_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    settings='{
        "base_url":"http://host.docker.internal:8787/v1",
        "timeout":60
    }'
);
```

## Model Identifier Format

Buddy stores `llm_provider` and `llm_model` separately, then builds the extension model id.

Supported patterns:

- `llm_provider='openai', llm_model='gpt-4o-mini'`
- `llm_provider='openai', llm_model='openai:gpt-4o-mini'`

Runtime behavior:

- if `llm_model` does not contain `:`, Buddy builds `provider:model`
- if `llm_model` already contains `:`, Buddy uses it as-is

The recommended SQL form is still:

```sql
llm_provider='openai',
llm_model='gpt-4o-mini'
```

Examples in this document use `openai`, but provider support is defined by the installed `llm` extension rather than by a hardcoded provider whitelist in Buddy.

## CREATE RAG MODEL

### Required fields

- `llm_provider`
- `llm_model`

### Common optional fields

- `style_prompt`
- `temperature`
- `max_tokens`
- `top_p`
- `frequency_penalty`
- `presence_penalty`
- `k_results`
- `similarity_threshold`
- `max_document_length`
- `settings`

### Recommended syntax

Put provider transport options and other extension constructor options into `settings`.

```sql
CREATE RAG MODEL advanced_assistant (
    llm_provider='openai',
    llm_model='gpt-4o',
    style_prompt='You are a helpful assistant specializing in search technology',
    settings='{
        "api_key":"sk-...",
        "base_url":"http://host.docker.internal:8787/v1",
        "timeout":60,
        "temperature":0.3,
        "max_tokens":2000,
        "top_p":0.9,
        "frequency_penalty":0.1,
        "presence_penalty":0.0,
        "k_results":5,
        "similarity_threshold":0.8,
        "max_document_length":3000
    }'
);
```

### Top-level numeric convenience fields

These fields can also be provided directly:

```sql
CREATE RAG MODEL simple_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    temperature=0.7,
    max_tokens=1000,
    k_results=5
);
```

Validation enforced by Buddy:

- `temperature`: `0..2`
- `max_tokens`: `1..32768`
- `k_results`: `1..50`

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
- `llm_provider`
- `llm_model`
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

- `settings.temperature`
- `settings.max_tokens`
- `settings.k_results`
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
    'content_fields',
    'optional_conversation_uuid'
);
```

Positional parameters:

| Position | Parameter | Required | Description |
|---:|---|---:|---|
| 1 | `query` | Yes | User question |
| 2 | `table` | Yes | Table to search |
| 3 | `model_name_or_uuid` | Yes | RAG model name or UUID |
| 4 | `content_fields` | Yes | Comma-separated list of fields used to build context |
| 5 | `conversation_uuid` | No | Conversation UUID. If omitted, Buddy generates one |

Notes:

- `content_fields` is mandatory
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
- context building from the requested `content_fields`
- context truncation using `max_document_length`

Buddy-specific search settings:

- `k_results`: number of documents retrieved for KNN
- `similarity_threshold`: KNN distance threshold
- `max_document_length`: per-document context truncation size

## Tested Examples

The basic end-to-end flow covered by CLT includes:

1. create a vectorized `docs` table
2. insert documents
3. create a minimal RAG model
4. create an advanced RAG model with `settings`
5. run `SHOW RAG MODELS`
6. run `DESCRIBE RAG MODEL`
7. run `CALL CONVERSATIONAL_RAG`
8. drop the models

Representative examples from that flow:

```sql
CREATE RAG MODEL test_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini'
);
```

```sql
CREATE RAG MODEL advanced_assistant (
    llm_provider='openai',
    llm_model='gpt-4o',
    style_prompt='You are a helpful assistant specializing in search technology',
    settings='{"temperature":0.3, "max_tokens":2000, "k_results":5}'
);
```

```sql
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'docs',
    'test_assistant',
    'content'
);
```

## Recommended Usage

For production usage with an OpenAI-compatible proxy, prefer this pattern:

```sql
CREATE RAG MODEL assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini',
    style_prompt='You answer only from the provided context.',
    settings='{
        "api_key":"proxy-key",
        "base_url":"http://host.docker.internal:8787/v1",
        "timeout":60,
        "temperature":0.2,
        "max_tokens":1024,
        "k_results":5,
        "similarity_threshold":0.8,
        "max_document_length":2000
    }'
);
```

This keeps:

- provider transport settings in `settings`
- Buddy search controls in `settings`
- prompt behavior in `style_prompt`
