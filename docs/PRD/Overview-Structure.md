# Atlas Nexus Overview

## Database

### `ai_assistants`

```
- id                  bigint PK
- slug                string unique
- name                string
- description         text nullable
- default_model       string nullable
- temperature         decimal(3,2) nullable
- top_p               decimal(3,2) nullable
- max_output_tokens   int nullable
- current_prompt_id   bigint nullable     -- no FK
- is_active           boolean default 1
- metadata            json nullable
- created_at
- updated_at
- deleted_at
```

### `ai_prompts`

Versioned system prompts for each assistant.

```
- id                  bigint PK
- user_id             bigint nullable   -- no FK
- assistant_id        bigint            -- no FK
- version             int               -- 1,2,3...
- label               string nullable
- system_prompt       text
- variables_schema    json nullable
- is_active           boolean default 1
- created_at
- updated_at
- deleted_at

-- You enforce uniqueness in code, not via FK:
-- UNIQUE (assistant_id, version)
```

### `ai_threads`

One conversation session. Supports user-facing threads and tool-internal threads.

```
- id                  bigint PK
- assistant_id        bigint              -- no FK
- user_id             bigint              -- no FK
- type                string              -- 'user','tool'
- parent_thread_id    bigint nullable     -- no FK, root user thread if nested
- parent_tool_run_id  bigint nullable     -- no FK, tool run that spawned this thread
- title               string nullable
- status              string              -- 'open','archived','closed'
- prompt_id           bigint nullable     -- no FK
- summary             text nullable       -- rolling summary (short-term memory)
- last_message_at     datetime nullable
- metadata            json nullable
- created_at
- updated_at
```

### `ai_messages`

Every message in a thread.

```
- id                   bigint PK
- thread_id            bigint            -- no FK
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
- metadata             json nullable
- created_at
- updated_at

-- INDEX(thread_id, sequence)
```

### `ai_tools`

Registry of all available tools.

```
- id                  bigint PK
- slug                string unique
- name                string
- description         text nullable
- schema              json              -- JSON schema for arguments
- handler_class       string            -- Laravel class that implements the tool
- is_active           boolean default 1
- created_at
- updated_at
- deleted_at
```

### `ai_assistant_tool`

Which tools each assistant can use (pivot table).

```
- id                  bigint PK
- assistant_id        bigint        -- no FK
- tool_id             bigint        -- no FK
- config              json nullable
- created_at
- updated_at

-- unique pair (assistant_id, tool_id)
```

### `ai_tool_runs`

Actual execution logs for tools.
Each tool run is related to the assistant message that invoked it and may use its own internal thread.

```
- id                          bigint PK
- tool_id                     bigint              -- no FK
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

### `ai_memories`

Shared memory items that can be reused across threads.

```
- id                  bigint PK
- owner_type          string            -- 'user','assistant','org'
- owner_id            bigint            -- no FK, e.g. users.id when owner_type='user'
- assistant_id        bigint nullable   -- no FK, which assistant this memory is for (null = global)
- thread_id           bigint nullable   -- no FK, where it was first observed (provenance)
- source_message_id   bigint nullable   -- no FK, ai_messages.id that produced this memory
- source_tool_run_id  bigint nullable   -- no FK, ai_tool_runs.id if created from a tool
- kind                string            -- 'fact','preference','summary','task','constraint', etc.
- content             text              -- natural-language memory; 1–3 sentences ideally
- metadata            json nullable     -- tags, domains, app-specific fields
- created_at
- updated_at
```
