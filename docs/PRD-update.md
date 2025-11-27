# Atlas Nexus

Atlas Nexus is a Laravel package that enables consumers to install, configure, and manage AI agents and assistants. It centralizes prompts, tools, context, and thread handling while integrating with Prism to communicate with AI providers.

## Table of Contents

* [About](#about)
* [Features](#features)
* [AI Agents](#ai-agents)
* [Prompt Attributes](#prompt-attributes)
* [Threads](#threads)
* [Thread Messages](#thread-messages)
* [Thread Hooks](#thread-hooks)
* [Agent Tools](#agent-tools)
* [Agent Context](#agent-context)

## About

Atlas Nexus provides a configurable AI orchestration system for Laravel applications. It allows developers to define agents, prompts, attributes, context, tools, and thread lifecycles using consistent interfaces and configuration arrays.

## Features

Atlas Nexus must provide:

1. **Prism AI Requests** — Ability to send and receive AI requests through Prism, supporting providers such as OpenAI and Gemini.
2. **AI Agent Registration** — Consumers can register custom agents in the config as an array of agent definitions.
3. **Prompt Attributes** — Consumers may register variable groups in the config to support macro-style replacements (e.g., `{USER.NAME}`).
4. **Thread Hooks** — Consumers may register custom hooks in the config to manipulate thread behavior (memory, summaries, or anything else).
5. **Agent Tools** — Consumers may register tool definitions in the config, assign tools to agents, and utilize provider tools.
6. **Agent and Consumer Context** — Support for adding context through tools or agents, such as summaries, memory, or any additional contextual data.

## AI Agents

Atlas Nexus includes built-in agents:

* General Agent
* Human Agent
* Thread Summary Agent
* Memory Agent

Consumers may add their own agents or extend built-in agents in the configuration file.

### Agent Specification

Agents are **class-based** and **not database-stored**. Each agent must support these fields:

* **key** — Unique identifier.
* **name** — Human-readable label.
* **system prompt** — Required core prompt defining instructions, tone, rules, and personality.
* **description** — Human-readable explanation of the agent.
* **context prompt** — Optional contextual message inserted after the system prompt and before the user's first message.
* **model** — AI model to use.
* **temperature** — Model temperature.
* **topP** — topP sampling value.
* **maxOutputTokens** — Maximum allowed output tokens.
* **maxDefaultSteps** — Maximum steps before returning a response.
* **isActive** — Whether the agent is enabled.
* **isHidden** — Whether the agent is visible to users.
* **tools** — Array of tool keys plus configuration options.
* **providerTools** — Array of provider tool keys plus configuration options.
* **reasoning** — Array controlling reasoning behavior.

Agents must implement a shared interface or an abstract class to ensure consistency.

### Database

**Table: `ai_agents`**
Used for reference only; agent data is class-defined.

## Prompt Attributes

Prompt attributes allow variable replacement in system prompts, context prompts, and any other prompts using macros such as `{USER.NAME}`.

Consumers may register Prompt Attribute classes in the configuration file.

Each attribute class must:

* Implement `PromptVariableGroup`.
* Implement `handle(Thread $thread)` to resolve variables.

## Threads

**Table: `ai_threads`**

Threads represent unique conversations between the user and an AI agent.

## Thread Messages

**Table: `ai_thread_messages`**

Every message in a thread must be recorded here, including:

* system
* context
* agent
* user

Each message must store:

* message type
* model used
* tokens in and out
* raw provider output
* user feedback (+1 / -1) for agent messages

This ensures full auditability and historical context.

## Thread Hooks

Thread hooks allow consumers to modify thread behaviors during lifecycle events.

Hooks must be defined as an array in the configuration file and can:

* Manage memory
* Create thread summaries
* Run custom consumer-defined logic

Hooks must support frequency execution such as:

* "Run this hook every 10 messages"

Execution counts include post-agent messages.

## Agent Tools

**Table: `ai_thread_message_tools`**

This table logs each tool invocation and the raw response returned.

### Custom Tools

Consumers can:

* Register tool classes in configuration.
* Assign tools to specific agents.
* Pass configuration options to tools.
* Implement shared interfaces for consistency.

### Provider Tools

Atlas Nexus must support provider-level tools such as:

* `web_search`
* `file_search`
* `code_interpreter`

Each tool may define configuration such as:

* allowed domains
* file vector IDs

### Built-in Application Tool

A built-in tool `fetch_more_context` must be included.

This tool must retrieve:

* thread summaries
* thread titles
* keywords
* total number of threads
* total number of user messages

It must support searching across:

* titles
* summaries
* keywords
* messages

This gives agents access to historical context.

## Agent Context

**Table: `ai_agent_context`**

Consumers and agents may store:

* memories
* summaries
* any additional contextual data

A column `type` must define the context category (e.g., memory, summary).
