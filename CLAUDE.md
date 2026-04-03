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
| `php index.php --at-cli` | Open an interactive agent CLI session |

## Migrations

Migration files live in `site/assets/at/migrations/` and are named:
`YYYYMMDDhhmmss_description.php`

The applied migrations registry is stored in the database (AgentTools module config),
so it is never overwritten by rsync or file transfers.

Migrations can also be applied from the ProcessWire admin at **Setup > Agent Tools**.

## Further reading

- `agent_cli.md` — full details on migrations, `--at-cli` session usage, and conventions
