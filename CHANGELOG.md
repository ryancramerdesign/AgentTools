# Changelog

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
