# Atlas Nexus

**Atlas Nexus** is a Laravel package that provides a complete AI interaction framework for managing chat threads, prompt pipelines, long-term memory, contextual state, and tool execution through Prism. It centralizes all AI messaging logic into a cohesive system that can power assistants, agents, and automated workflows across any application.

## Contributing
See the [Contributing Guide](./.github/CONTRIBUTING.md).

## Sandbox

A dedicated Laravel sandbox ships in [`sandbox/`](./sandbox) so you can exercise Nexus + Prism flows outside of a consumer app.

1. Install dependencies:
   ```bash
   cd sandbox
   composer install
   ```
2. Copy or edit `.env` to point at your local MySQL instance and set any Prism provider keys (`OPENAI_API_KEY`, etc.), then run the database migrations:
   ```bash
   php artisan migrate
   ```
3. Execute the CLI explorer commands to run real prompts against the configured Prism provider:
   ```bash
   php artisan nexus:pipeline "Summarize today's tasks" --stream
   php artisan prism:text "Test single prompt handling"
   php artisan prism:chat --stream
   php artisan sandbox:log-chat "Persist custom payload for review"
   php artisan sandbox:list-chats
   php artisan sandbox:view-chat 20250101_120000_000000.txt
   ```

The sandbox uses the checked-in `config/atlas-nexus.php` to describe pipelines, so feel free to duplicate and tweak entries as you experiment with additional providers, models, and system prompts.

Tools often require multiple inference steps, so the sandbox honors a `PRISM_MAX_STEPS` environment variable (defaults to `8`). Increase it in `sandbox/.env` if your provider requests more tool iterations.

## License
MIT — see [LICENSE](./LICENSE).
