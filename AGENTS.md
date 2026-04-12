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
| `php index.php --at-eval 'CODE'` | Evaluate a PHP expression with full PW API access |
| `echo 'CODE' \| php index.php --at-stdin` | Evaluate multi-line PHP code from stdin |
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List migrations and their status |
| `php index.php --at-migrations-test` | Preview pending without applying |
| `php index.php --at-sitemap-generate` | Generate a JSON site map to `site/assets/at/site-map.json` |
| `php index.php --at-sitemap-generate-schema` | Generate a schema JSON to `site/assets/at/site-map-schema.json` |
| `php index.php --at-cli` | Open an interactive agent CLI session |

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

Migrations can also be applied from the ProcessWire admin at **Setup > Agent Tools**.


## Further reading

- `agent_cli.md` — full details on migrations, `--at-cli` session usage, and conventions
