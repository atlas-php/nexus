# Atlas Nexus Overview

## Database

### `ai_threads`

One conversation session. Supports user-facing threads and tool-internal threads.

```
- id                  bigint PK
- assistant_key       string
- user_id             bigint              -- no FK
- type                string              -- 'user','tool'
- parent_thread_id    bigint nullable     -- no FK, root user thread if nested
- parent_tool_run_id  bigint nullable     -- no FK, tool run that spawned this thread
- title               string nullable
- status              string              -- 'open','archived','closed'
- summary             text nullable       -- rolling summary (short-term memory)
- last_message_at     datetime nullable
- last_summary_message_id bigint nullable -- most recent message id summarized
- memories            text nullable       -- JSON array of durable memories for this thread
- metadata            json nullable
- deleted_at          datetime nullable
- created_at
- updated_at
```

### `ai_messages`

Every message in a thread.

```
- id                   bigint PK
- thread_id            bigint            -- no FK
- assistant_key        string
- user_id              bigint nullable   -- null for assistant messages
- role                 string            -- 'user','assistant'
- content              text              -- text or JSON
- content_type         string            -- 'text','json'
- sequence             int               -- order in thread
- status               string            -- 'processing','completed','failed'
- failed_reason        text nullable     -- populated when status='failed'
- model                string nullable
- tokens_in            int nullable
- tokens_out           int nullable
- provider_response_id string nullable
- is_memory_checked    boolean            -- false until the memory extractor processes it
- metadata             json nullable
- deleted_at           datetime nullable
- created_at
- updated_at

-- INDEX(thread_id, sequence)
-- INDEX(is_memory_checked)
```

### `ai_message_tools`

Actual execution logs for tools.
Each tool run is related to the assistant message that invoked it and may use its own internal thread.

```
- id                          bigint PK
- tool_key                    string              -- tool registry key
- assistant_key               string
- thread_id                   bigint              -- no FK (user or tool thread where this run is logged)
- assistant_message_id        bigint              -- no FK (the assistant message that created tool calls)
- call_index                  int                 -- 0,1,2... within the assistant response
- input_args                  json                -- parsed LLM arguments
- status                      string              -- 'queued','running','succeeded','failed'
- response_output             json nullable       -- structured tool response
- metadata                    json nullable       -- timing, token usage, extra details
- error_message               text nullable
- started_at                  datetime nullable
- finished_at                 datetime nullable
- created_at
- updated_at
```

### Thread Memories

Durable memories now live on `ai_threads.memories` as a JSON array. Each entry follows:

```
- content               string            -- concise natural-language memory
- thread_id             bigint            -- automatically set to owning thread id
- (removed) `source_message_ids`
- created_at            datetime string   -- ISO8601 timestamp when stored
```

Memories are appended by the memory extractor assistant and deduplicated per thread and user.
