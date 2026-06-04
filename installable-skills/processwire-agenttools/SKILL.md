---
name: processwire-agenttools
description: Provides ProcessWire CLI and migration workflows for sites using the AgentTools module. Use when querying the ProcessWire API, making repeatable site changes, or transferring changes across environments.
---

# ProcessWire AgentTools

CLI access to the ProcessWire API and a file based migration system.

## Source Of Truth

This installed skill is a copied convenience package. If this project contains
`site/modules/AgentTools/`, prefer that module's root `AGENTS.md` for AgentTools
development and current operating instructions. The packaged source files in
`site/modules/AgentTools/installable-skills/processwire-agenttools/` may also be
newer than this installed copy.

Use this installed skill as a portable helper when the module checkout is not yet
known or available, or when you only need the standard ProcessWire CLI and
migration workflow.

The module registers `$at` as a ProcessWire API variable (`wire('at')`).

## Commands

Run from the ProcessWire root directory (where `index.php` lives).

| Command | Purpose |
|---------|---------|
| `php index.php --at-eval 'CODE'` | Evaluate a PHP expression with full PW API access |
| `echo 'CODE' \| php index.php --at-stdin` | Evaluate multi-line PHP code from stdin |
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List migrations and their status |
| `php index.php --at-migrations-test` | Preview pending without applying |
| `php index.php --at-sitemap-generate` | Generate site map JSON to `site/assets/at/site-map.json` |
| `php index.php --at-sitemap-generate-schema` | Generate schema JSON to `site/assets/at/site-map-schema.json` |
| `php index.php --at-cli` | Open interactive agent CLI session |
| `php index.php --at-engineer "REQUEST"` | Ask the Engineer a question or request a change |
| `php index.php --at-engineer-migrate "REQUEST"` | Have the Engineer create a migration; outputs the migration file path |
| `php index.php --at-engineer-site-info pages\|schema\|modules [--refresh]` | Print generated site info JSON without calling an AI provider |
| `php index.php --at-engineer-api-docs-list` | List available ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-api-docs-get NAME` | Print a ProcessWire API.md documentation file without calling an AI provider |
| `php index.php --at-engineer-api-docs-search TERM` | Search ProcessWire API.md documentation without calling an AI provider |
| `php index.php --at-engineer-read-file PATH` | Read a local site file without calling an AI provider |

## Getting oriented on a new site

If you are working on a site for the first time, run:

```bash
php index.php --at-sitemap-generate
```

Then read `site/assets/at/site-map.json` to understand the site's templates,
fields, page tree, and installed modules before making changes.

If you need full field/template configuration details, including type-specific
field settings, per-template field context overrides, and template settings, also
run:

```bash
php index.php --at-sitemap-generate-schema
```

Then read `site/assets/at/site-map-schema.json`. The file contains a top-level
`_readme` key with instructions for interpreting the schema; read it before using
the data.

## Compatibility wrapper

Normal AgentTools usage is the direct `php index.php --at-*` commands above.
The `scripts/pw-at.sh` wrapper is available for Docker or similar environments
where the agent runtime needs help with DDEV/container selection or command
transport. It preserves the same command model while adapting runtime details.
Use it when direct PHP commands are not reliable in the current environment, or
when you are specifically debugging wrapper behavior.

```bash
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh eval 'CODE'
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh stdin
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-apply
```

## Engineer

The Engineer is available in the ProcessWire admin at
**Setup > Agent Tools > Engineer** and from the command line. It can use
`eval_php`, `save_migration`, `site_info`, `read_file`, `api_docs`, and `save_memory`.
It supports multi-turn conversation history within a session and optional persistent memory
when the user explicitly asks it to remember durable site/workflow preferences.

```bash
php index.php --at-engineer "How many published pages does this site have?"
php index.php --at-engineer-migrate "Add a text field called subtitle to the blog-post template"
php index.php --at-engineer-api-docs-list
php index.php --at-engineer-site-info schema
```

Optional flags go before the request string:

- `--model=N` — use agent at index N, where 0 is the primary configured agent
- `--readonly` — allow queries only; available for `--at-engineer`
- `--verbose` — write tool call names to stderr as they execute

When a migration is created, the response is followed by a
`Migration: /full/path/to/file.php` line.

## Available API variables

All CLI modes and migrations share the same variables:
`$pages`, `$templates`, `$fields`, `$fieldgroups`, `$modules`, `$config`, `$sanitizer`, `$users`, `$roles`, `$permissions`, `$session`, `$database`, `$cache`, `$log`, `$files`, `$at`

## When to use Migrations vs CLI

For one-off reads or changes, use the CLI. For environment-transferable changes, create a migration. Use `cli` only for multi-step interactive work.

| Operation | Default path | Reason |
|-----------|-------------|--------|
| Queries, listing, inspecting | CLI | No state change to transfer |
| Templates, fields, fieldgroups | Migration | Schema should match across environments |
| Pages | Ask the user | Could be seed data or environment-specific |
| Module config, roles, permissions | Migration | Config drift causes subtle bugs |
| Debugging, testing | CLI | Throwaway by nature |

## Environment overrides

These apply only to the compatibility wrapper:

- `PW_AT_RUNNER=auto|host|ddev` — force a specific runner instead of auto-detection
- `PW_AT_PHP_CMD=php` — override the PHP binary

## Reference

- **Running PHP against the ProcessWire API** (queries, one-off changes, testing) — read [cli.md](cli.md)
- **Creating migrations** (repeatable, transferable changes across environments) — read [migrations.md](migrations.md)
- **Validating the wrapper layer** (only when debugging portability/transport issues) — read [testing.md](testing.md)
