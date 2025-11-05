# ConversationalRag Plugin

The ConversationalRag plugin enables conversational Retrieval-Augmented Generation (RAG) capabilities in ManticoreSearch. It combines vector search with OpenAI's language models to provide intelligent, context-aware responses based on your data.

## Features

- **Conversational AI**: Maintains conversation context with automatic UUID generation
- **Vector Search**: Uses KNN search on float_vector fields to find relevant documents  
- **OpenAI Integration**: Uses OpenAI models (gpt-4o, gpt-4o-mini, gpt-3.5-turbo, etc.)
- **Intent Classification**: Detects user intent (NEW_SEARCH, ALTERNATIVES, TOPIC_CHANGE, etc.)
- **Dynamic Thresholds**: Adjusts search similarity thresholds based on conversation flow
- **SQL-based Management**: Native SQL syntax for model and conversation management

## Quick Start

### 1. Prerequisites

- ManticoreSearch with Buddy plugin enabled
- OpenAI API key (set as environment variable `OPENAI_API_KEY`)
- Table with vector embeddings (float_vector field)

### 2. Environment Setup

```bash
# Set your OpenAI API key
export OPENAI_API_KEY="sk-proj-your-api-key-here"
```

### 3. Create a RAG Model

```sql
CREATE RAG MODEL my_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini'
);
```

### 4. Start a Conversation

```sql
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'documents_table',
    'my_assistant'
);
```

## Detailed Usage

### Model Management

#### Creating Models

```sql
-- Basic model
CREATE RAG MODEL basic_assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini'
);

-- Advanced model with custom settings
CREATE RAG MODEL advanced_assistant (
    llm_provider='openai',
    llm_model='gpt-4o',
    style_prompt='You are an expert database administrator. Provide detailed, technical answers with examples.',
    temperature=0.3,
    max_tokens=4000,
    k_results=10,
    similarity_threshold=0.8,
    max_document_length=3000
);

-- Model with JSON settings
CREATE RAG MODEL custom_assistant (
    llm_provider='openai',
    llm_model='gpt-3.5-turbo',
    settings='{"temperature": 0.5, "top_p": 0.9, "frequency_penalty": 0.1}'
);
```

#### Viewing Models

```sql
-- List all models
SHOW RAG MODELS;

-- Describe specific model
DESCRIBE RAG MODEL my_assistant;
```

#### Dropping Models

```sql
DROP RAG MODEL my_assistant;
```

### Conversational Queries

#### Basic Conversation

```sql
-- Start new conversation
CALL CONVERSATIONAL_RAG(
    'Explain full-text search in ManticoreSearch',
    'knowledge_base',
    'my_assistant'
);

-- Continue conversation (generates new UUID if not provided)
CALL CONVERSATIONAL_RAG(
    'How does it compare to vector search?',
    'knowledge_base',
    'my_assistant'
);
```

#### Conversation with UUID Tracking

```sql
-- Start conversation with specific UUID
CALL CONVERSATIONAL_RAG(
    'What are the best indexing strategies?',
    'documents',
    'my_assistant',
    'conv-12345678-1234-1234-1234-123456789abc'
);

-- Continue same conversation
CALL CONVERSATIONAL_RAG(
    'Can you give me a practical example?',
    'documents', 
    'my_assistant',
    'conv-12345678-1234-1234-1234-123456789abc'
);
```

#### Runtime Parameter Overrides

```sql
-- Override model settings for specific query
CALL CONVERSATIONAL_RAG(
    'Give me a detailed explanation',
    'docs_table',
    'my_assistant',
    'conversation-uuid',
    '{"temperature": 0.1, "max_tokens": 6000, "k_results": 15}'
);
```

## Data Preparation

### Table Requirements

Your table must have:
- A vector field (float_vector type) containing embeddings
- Content fields with the actual document content

```sql
-- Example table schema
CREATE TABLE docs (
    id bigint,
    content text,
    embedding_vector float_vector(768)
);
```

### Vector Field Detection

The plugin automatically detects vector fields by:
1. Looking for fields with `FLOAT_VECTOR` type
2. Checking common names: `embedding_vector`, `embedding`, `vector`, `embeddings`

## Configuration Options

### Model Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `llm_provider` | string | Yes | - | Must be 'openai' |
| `llm_model` | string | Yes | - | OpenAI model (gpt-4o-mini, gpt-4o, gpt-3.5-turbo) |
| `style_prompt` | string | No | '' | System prompt for the LLM |
| `temperature` | float | No | 0.7 | Creativity level (0.0-2.0) |
| `max_tokens` | int | No | 4000 | Maximum response length (1-32768) |
| `k_results` | int | No | 5 | Number of documents to retrieve (1-50) |
| `settings` | JSON string | No | - | Additional settings as JSON |

### Runtime Overrides

Some parameters can be overridden per query:

```sql
CALL CONVERSATIONAL_RAG(
    'Your question here',
    'table_name',
    'model_name',
    'conversation_uuid',
    '{"temperature": 0.9, "k_results": 20}'
);
```

## Advanced Features

### Intent Classification

The plugin automatically classifies user intent to optimize search:

- **NEW_SEARCH**: Fresh search with no prior context
- **ALTERNATIVES**: User wants more options ("what else?")
- **TOPIC_CHANGE**: User switching topics ("show me comedies instead")
- **INTEREST**: User likes content, wants similar items
- **REJECTION**: User doesn't like shown content
- **QUESTION**: User asking about specific content
- **CLARIFICATION**: User providing additional details
- **UNCLEAR**: Cannot determine intent

### Dynamic Thresholds

The system automatically adjusts similarity thresholds based on:
- Conversation history
- User intent (expansion requests increase threshold)
- Previous search results effectiveness

### Exclusion Handling

The plugin intelligently excludes previously shown or rejected content:

```sql
-- User says: "I already watched Breaking Bad but want similar shows"
-- Plugin automatically excludes "Breaking Bad" from results
```

## Response Format

Conversation responses include:

```json
{
    "conversation_uuid": "generated-or-provided-uuid",
    "response": "AI-generated response text",
    "sources": "[{\"id\": 1, \"title\": \"Doc Title\", \"content\": \"...\"}, ...]"
}
```

## Examples

### Basic Usage

```sql
-- Create a model
CREATE RAG MODEL assistant (
    llm_provider='openai',
    llm_model='gpt-4o-mini'
);

-- Ask a question
CALL CONVERSATIONAL_RAG(
    'What is vector search?',
    'docs',
    'assistant'
);

-- Continue conversation
CALL CONVERSATIONAL_RAG(
    'How does it work?',
    'docs',
    'assistant',
    'returned-conversation-uuid'
);
```

## Common Issues

### Missing API Key
```bash
# Error: Environment variable 'OPENAI_API_KEY' not found
export OPENAI_API_KEY="sk-proj-your-key-here"
```

### No Vector Field  
```sql
-- Error: No vector field found in table
-- Solution: Add float_vector field
ALTER TABLE docs ADD COLUMN embedding_vector float_vector(768);
```

### Model Not Found
```sql
-- Check existing models
SHOW RAG MODELS;
```

## Tables Created

The plugin automatically creates these tables:
- `system.rag_models` - Model configurations
- `rag_conversations` - Conversation history (24h TTL)