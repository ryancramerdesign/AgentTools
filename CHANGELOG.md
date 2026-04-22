# Changelog

## Version 6

### Engineer

- **Conversation memory** — Yes/No radio in Control room; when enabled, prior exchanges are stored in the PHP session and included with each request so the Engineer can refer back to them; memory preference persisted per user via `$user->meta()`; defaults to enabled
- **Inline reply form** — when memory is enabled, a compact reply textarea appears below each response so the conversation can continue without leaving the page
- **Conversation history display** — collapsed `InputfieldMarkup` in the Engineer form shows prior exchanges (user messages as blockquotes, assistant replies rendered) when memory is on and history exists; labelled with exchange count
- **Reset conversation** — checkbox in Control room (shown when memory is on and history exists) clears session history; displays current history size in kb
- **`site_info` tool** — agent can now fetch the site's page tree (`type='pages'`) or fields/templates schema (`type='schema'`) on demand; sitemaps are no longer pre-loaded into the system prompt
- **`api_docs` tool** — agent can now discover (`action='list'`) and retrieve (`action='get', name='...'`) ProcessWire API.md documentation on demand; replaces keyword-based pre-loading heuristic
- **Control room simplified** — "Extra context to include" selector removed entirely; Control room now contains only Model and Memory; label summarises current settings (e.g. `Control room — claude-sonnet-4-6 · Memory: On`)
- Control room always collapsed; summary label reflects current model and memory state at a glance
- OpenRouter added to Additional models examples in module config

### Bug fixes

- Fixed URL-in-label bug in `parseAdditionalModelLine()` when a 4-part pipe-separated line has a URL as the 4th segment
- Removed `requestNeedsFieldtypeDocs()` and `getFieldtypeApiDocs()` (superseded by `api_docs` tool)

---

## Version 5

### Engineer

- **Multi-model support** — configure additional AI providers/models beyond the primary; each uses its own API key and endpoint
- **Additional models** textarea in module config accepts one model per line in pipe-separated format: `model | api-key`, `model | api-key | endpoint`, or `model | api-key | endpoint | label`; provider is auto-detected from the key prefix (`sk-ant-*` = Anthropic, all others = OpenAI-compatible); whitespace around pipes is optional; lines beginning with `#` are ignored
- **Control room** collapsible fieldset in the Engineer form with model selector and context options; auto-expands when non-default settings are saved
- **Context selector** radio in Control room: *All* (site maps + API docs), *Custom* (choose individual items), or *None* (no extra context, useful for general questions or token-limited providers)
- **Persist Control room selections** per user via `$user->meta('AgentTools')` — model index, context mode, and custom context items are remembered across sessions
- **Debug notices** after each Engineer request listing which context items were actually included in the system prompt
- System prompt now instructs the AI to combine all changes for a single request into one migration file
- System prompt now instructs the AI to format Unix timestamps as human-readable date strings when displaying results
- Improved API error handling — shows the actual error body when the response format doesn't match the expected structure

### Module config

- Primary AI provider settings wrapped in a *Primary AI provider* fieldset
- Primary model field now accepts a comma-separated list of model IDs to expose multiple models from the same provider
- Additional models textarea with inline format documentation and copy-paste examples for OpenAI, Anthropic, Google Gemini, Groq/Llama, and local Ollama

### Bug fixes

- Removed deprecated `curl_close()` call (PHP 8.5)

---

## Version 4

### Engineer (new feature)

- Added `AgentToolsEngineer` class providing a natural language AI interface to the ProcessWire API
- Supports Anthropic (Claude) and OpenAI-compatible providers
- Two tools available to the AI: `eval_php` (query live site data) and `save_migration` (create migration files for review)
- AI-generated migrations include an embedded markdown summary as a PHP docblock
- Read-only mode config toggle (`engineer_readonly`) — Engineer can answer questions but cannot execute code or create migrations
- Fieldtype API reference (from `wire/modules/Fieldtype/*/API.md`) conditionally included in system prompt: always for Anthropic (cached), keyword-detected for other providers
- Anthropic prompt caching with 1-hour TTL applied to system prompt and tool definitions, reducing cost and latency for multi-turn sessions
- Site map and schema automatically regenerated before Engineer requests if stale (detected via `fields.txt`/`templates.txt` tracking files and `pages.modified` DB column)
- System prompt instructs AI to verify current site state via `eval_php` before writing migrations

### Migrations

- `AgentToolsMigrations::getFiles(string $dir)` — get migration files sorted chronologically
- `AgentToolsMigrations::getDatetime(string $file)` — extract ISO-8601 datetime from filename
- `AgentToolsMigrations::getTitle(string $file)` — human-readable title derived from filename
- `AgentToolsMigrations::getSummary(string $file)` — extract embedded markdown summary from docblock
- `AgentToolsMigrations::getInfo(string $file)` — returns array of all migration metadata
- `AgentToolsMigrations::removeApplied(string $file)` — remove a migration from the applied registry

### Site map

- `AgentToolsSitemap::getModulesData()` now includes uninstalled modules via `$modules->getInstallable()`
- Hook in `AgentTools::ready()` writes to `site/assets/at/fields.txt` and `site/assets/at/templates.txt` on field/template save, add, or delete — used as a proxy for modification timestamps in staleness detection

### ProcessAgentTools admin UI (new feature)

- New admin application at **Setup > Agent Tools** with Migrations and Engineer sections
- **Engineer screen** — submit natural language requests to the AI; responses rendered with markdown support; "Modify my question" button pre-fills the form with the previous request; spinner and randomized "thinking" words while waiting for a response
- **Migrations list screen** — table of all migrations with status, datetime, and human-readable title; checkbox bulk actions (apply checked, delete checked with confirmation); "New migration" button links to Engineer; first column not sortable
- **View migration screen** — shows metadata (status, date, file path), embedded summary, full source code, and apply/re-apply button; breadcrumb navigation; "Review and apply migration" button from Engineer links directly to this screen
- Site map and schema automatically regenerated after migrations are successfully applied
- `isMarkdown()` helper for detecting markdown in AI responses before choosing a renderer
- `formatEngineerResponse()` renders markdown, preformatted, or plain text responses with UIkit class injection

### Module config

- Engineer API provider, API key, model, endpoint URL, and read-only mode configurable in module settings
- Model field includes `<datalist>` autocomplete with known Anthropic and OpenAI model identifiers
- Uninstall option to also delete `site/assets/at/` files

### Documentation

- `AGENTS.md` — updated with `--at-sitemap-generate-schema` command
- `agent_cli.md` — updated with `--at-sitemap-generate-schema` command
- `agents/skills/processwire-agenttools/SKILL.md` — added sitemap commands
- `README.md` — updated to reflect Engineer feature, admin UI, prompt caching, and new CLI commands
