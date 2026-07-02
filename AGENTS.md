# AgentTools

AgentTools is a ProcessWire module that gives AI coding agents CLI access to the
ProcessWire API and provides a database migration system for transferring changes
across environments.

## API variable

The module registers `$at` as a ProcessWire API variable (`wire('at')`).

## CLI commands

Run from the ProcessWire root directory (where `index.php` lives):

| Command | Purpose |
|---------|---------|
| `php index.php --at-eval [--readonly] 'CODE'` | Evaluate a PHP expression with full PW API access |
| `echo 'CODE' \| php index.php --at-stdin [--readonly]` | Evaluate multi-line PHP code from stdin |
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List migrations and their status |
| `php index.php --at-migrations-test` | Preview pending without applying |
| `php index.php --at-migrations-lint` | Check migration syntax and AgentTools conventions |
| `php index.php --at-migrations-rerun --file=FILE` | Re-run one migration even if already applied |
| `php index.php --at-sitemap-generate` | Generate a JSON site map to `site/assets/at/site-map.json` |
| `php index.php --at-sitemap-generate-schema` | Generate a schema JSON to `site/assets/at/site-map-schema.json` |
| `php index.php --at-cli` | Open an interactive agent CLI session |
| `php index.php --at-engineer "REQUEST"` | Ask the Engineer a question or request a change |
| `php index.php --at-engineer-migrate "REQUEST"` | Have the Engineer create a migration; outputs the migration file path |
| `php index.php --at-engineer-site-info pages\|schema\|modules [--refresh]` | Print generated site info JSON without calling an AI provider |
| `php index.php --at-engineer-api-docs-list` | List available ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-api-docs-get NAME` | Print a ProcessWire API.md documentation file without calling an AI provider |
| `php index.php --at-engineer-api-docs-search TERM` | Search ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-read-file PATH` | Read a local site file without calling an AI provider |
| `php index.php --at-cron` | Process one pending AgentTools background job; intended for system cron |
| `php index.php --at-mcp` | Run the local AgentTools MCP server over stdio |

## Getting oriented on a new site

If you are working on a site for the first time, run:
```
php index.php --at-sitemap-generate
```
Then read `site/assets/at/site-map.json` to get a complete picture of the site's
templates, fields, page tree, and installed modules before making any changes.

If you need full field/template configuration details (type-specific field settings,
per-template field context overrides, all template settings), also run:
```
php index.php --at-sitemap-generate-schema
```
Then read `site/assets/at/site-map-schema.json`. The file contains a `_readme` key
at the top level with instructions on how to interpret the schema — read it before
using the data. This schema is particularly useful when generating migrations that
depend on existing field or template configuration.

## Migrations

Migration files live in `site/assets/at/migrations/` and are named:
`YYYYMMDDhhmmss_description.php`

The applied migrations registry is stored in the database (AgentTools module config),
so it is never overwritten by rsync or file transfers.

Migration CLI flags:

| Flag | Commands | Purpose |
|------|----------|---------|
| `--file=FILENAME.php` | `apply`, `test`, `list`, `lint`, `rerun` | Select one exact migration filename |
| `--name=NAME` | `apply`, `test`, `list`, `lint`, `rerun` | Select one migration by unique full or partial name |
| `--limit=N` | `apply`, `test` | Limit to the next N selected pending migrations |
| `--dry-run` | `apply`, `rerun` | Preview selected migrations without applying them |
| `--force` | `apply` | Re-run the selected migration even if it is already applied; requires `--file` or `--name` |

Agents should use these documented migration flags only. Do not invent unsupported
flag combinations or ad-hoc migration arguments not listed here.

Use `--readonly` with `--at-eval` or `--at-stdin` for inspection-only snippets on
unfamiliar sites. Read-only mode blocks common ProcessWire, database, and
filesystem mutation calls before code runs.

## MCP server

AgentTools can run as a local stdio MCP server:
```
php index.php --at-mcp
```

The initial MCP tool set is read-only: `at_status`, `at_site_info`,
`at_api_docs`, `at_read_file`, `at_migrations_list`, `at_migrations_lint`,
and `at_eval_readonly`. Do not use MCP for applying migrations or saving
changes unless a future AgentTools release explicitly documents write-capable
tools.

If an MCP client disconnects idle stdio servers, set `AGENTTOOLS_MCP_HEARTBEAT=30`
in that server command to send periodic JSON-RPC `ping` heartbeats.

Use MCP when your current client exposes AgentTools MCP tools directly and you need
read-only site inspection, API documentation lookup, file reads, migration listing/linting,
or small read-only ProcessWire queries. Use direct CLI commands for deterministic
automation and migration apply/test workflows. Use `--at-engineer` when you want
AgentTools' built-in ProcessWire-aware assistant to reason about a request or create
a migration.

Migrations can also be applied from the ProcessWire admin at **Setup > Agent Tools**.

## Background jobs

The admin Engineer, Page Engineer, and Tasks screens can queue long-running
requests as background jobs when system cron is configured. Cron should run
`php index.php --at-cron` from the ProcessWire root directory. Each run processes
one pending job and updates an AgentTools heartbeat file.

Agents may help create or review the cron command, but should not silently install
or modify a user's crontab without explicit permission. If background mode is
unavailable in the admin, check whether `php index.php --at-cron` runs
successfully from the ProcessWire root and report the result.


## Engineer (admin UI and CLI)

The ProcessAgentTools module provides a browser-based Engineer at **Setup > Agent Tools > Engineer**.
It connects to an AI provider (Anthropic, OpenAI, or any OpenAI-compatible endpoint) and gives it
six tools: `eval_php`, `save_migration`, `site_info` (fetches page tree or schema on demand),
`read_file` (reads local site files), `api_docs` (discovers and retrieves ProcessWire API.md documentation on demand),
and `save_memory` (saves durable site/workflow preferences when the user explicitly asks it to remember something).
The Engineer supports multi-turn conversation history within a session and optional persistent memory.

The Engineer is also available from the command line, which allows AI agents to spawn a
ProcessWire-specialist sub-agent without needing to understand ProcessWire themselves:

| Command | Purpose |
|---------|---------|
| `php index.php --at-engineer "REQUEST"` | Ask the Engineer a question or request a change |
| `php index.php --at-engineer-migrate "REQUEST"` | Have the Engineer create a migration; outputs the migration file path |
| `php index.php --at-engineer-site-info pages\|schema\|modules [--refresh]` | Print generated site info JSON without calling an AI provider |
| `php index.php --at-engineer-api-docs-list` | List available ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-api-docs-get NAME` | Print a ProcessWire API.md documentation file without calling an AI provider |
| `php index.php --at-engineer-api-docs-search TERM` | Search ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-read-file PATH` | Read a local site file without calling an AI provider |

Optional flags (placed before the request string):

| Flag | Purpose |
|------|---------|
| `--model=N` | Use agent at index N (0 = primary) as configured in module settings |
| `--readonly` | Allow queries only; the Engineer cannot create migrations |
| `--verbose` | Write tool call names to stderr as they execute |

The Engineer responds in plain text (stdout). Errors are written to stderr. When a migration is
created, the response is followed by a `Migration: /full/path/to/file.php` line, making it easy
to capture the file path for further processing.

**Example — querying site data:**
```
php index.php --at-engineer "How many published pages does this site have?"
```

**Example — creating a migration and capturing its path:**
```
migration=$(php index.php --at-engineer-migrate "Add a text field called subtitle to the blog-post template" | grep "^Migration:" | cut -d' ' -f2)
```

## Further reading

- `agent_cli.md` — full details on migrations, `--at-cli` session usage, and conventions
