# Atlas Nexus

## About

Atlas Nexus is a Laravel PHP package for consumers to install. It helps them setup  and manage their AI agents/assistants.

## Features:

1) Send and receive requests with prism for AI requests (using providers like OpenAI, Gemini, etc)
2) **AI Agents**: The package should allow consumers to add their own AI Agents in the config, an array of Agen definitions. 
3) **Prompt Attributes**: The package should allow consumers to add their own prompt attributes in the config, an array of variable classes.
4) **Thread hooks**: The package should allow consumers to add their own thread hooks in the config, an array of hook definitions to contorl things like memory and thread summaries and anything else the consumer wants to create. 
5) **Agent Tools**: The package should allow consumers to add their own tools in the config, an array of tool definitions that are connected to Agent for their use. (tools and provider tools).
6) **Agent Context**: The package should allow consumers and agents to add their own context through tools or agents like memory and summaries.

## AI agents

Built-in agents are provided "General Agent", "Human Agent", "Thread Summary Agent", and "Memory Agent". Additional agents can be added in the config file by the consumer and/or extending these agents for customization. 

Agents should support the following methods and features:

Table: `ai_agents`

(these are based on the class, not stored in the database, we want the consumers to extend these classes to override these features, and use an abstract or implements and interface for handling the agents functionality).

- key - a unique key for the agent
- name - a human readable name for the agent
- system prompt - the prompt that the agent will use to start the conversation
- description - a human readable description of the agent
- context prompt - a prompt that the agent will use to provide context to the conversation
- model - the model to use for the conversation
- temperature - the temperature to use for the conversation
- topP - the topP to use for the conversation
- maxOutputTokens - the max number of tokens to return from the conversation
- maxDefaultSteps - the max number of steps to take before returning from the conversation
- isActive - whether or not the agent is active
- isHidden - whether or not the agent is hidden from the user
- tools - an array of tools that the agent can use
- providerTools - an array of tools that the agent can use from providers
- reasoning - an array of reasoning that the agent can use

Both tools and providerTools should be an array of tool keys and configuration options that can be passed into those tools. 

Context prompt is optional, but it allows us to send an agent message before the user message of our thread (right after the system prompt) to give additional context for the conversation right before the user message.

System prompt is required and is our instructions, personality, and tone of the agent. 

## Prompt Attributes

Prompt attributes allow you to set up the ability to replace variables in any prompts using macro variables like "{USER.NAME}".

The consumer can add their own prompt attributes in the config file, it should be an array of classes to implement the PromptVariableGroup interface.

It should pass in "Thread" (for context) as an argument into the handle() which will be what all classes must implement.

## Threads

Table: `ai_threads`

Threads should be the specific conversations between the agent and the user.

## Thread Messages

Table: `ai_thread_messages`

Thread messages should be the specific messages between the agent and the user.

Each message in the thread should be stored here, either system prompt, context prompt (agent) and agent, or user messages. 

It should allow the user to vote up or down on the agent message either +1 or -1 to indicate that the agent was helpful or not helpful.

Every message should be saved with a type (system, context, agent, user) etc. This gives us full scope of what the history of the thread is so we understand the full context of the conversation.

Threads messages should give us the model used, the tokens in and out, and raw output so we can audit the data sent for every request. 

## Thread Hooks

Thread hooks allow you to control the behavior of threads in various ways. It allows the consumer to hook into the thread lifecycle and control things like memory and thread summaries. The main one is the after thread is complete hook, which allows the consumer to add a summary to the thread. It should include the ability to add a frequency of how often the thread hook runs. Example: "Run this hook every 10 messages", and this includes after the agent responds.

## Agent Tools

Table: `ai_thread_message_tools` (this table logs the usage of tools and the raw responses of these tools, they should be linked directly to the messages at which they were used).

Tools allow the consumer to add their own tools to agents and how they interact with actions of their systems. 

Agents have access to use these tools. 

Provider tools are built-in tools that we should support out of the box with openAI, like web_search, file_search, and code_interpreter. Each should have their options that they allow, like allowed domains and file vector ids. 

Tools are built in for the application, fetch_more_context tool is built in which allows an agent to fetch context from the user through their previous threads. This should include, summaries, keywords, and titles. It should include total number of threads, and total number of user messages. Searching threads should allow it to search through all titles, summaries, keywords, and messages to get the full context of what it previously had a conversation about.

Tools should have their own interfaces and array in the config to register each that is allowed to be added to specific agents. 

## Agent Context

Table: `ai_agent_context`

Consumers and agents can store memories and summaries in this table for more context.

There should be a `type` that defines memory, summary, or anything else the consumer wants to store related to more context. 


