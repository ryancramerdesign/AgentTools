# AgentTools for ProcessWire

Tools for using AI coding agents (e.g. Claude Code) to interact directly with a
ProcessWire installation, create database migrations, and apply them across environments.

---

## Getting started

**Step 1:** Install the AgentTools module in your ProcessWire installation.

**Step 2:** Make sure `php` is in your PATH. To verify:
~~~~~
php -v
~~~~~
If `php` is not found, add it to your PATH. On most Linux/macOS systems this means
adding the PHP binary directory to `$PATH` in your shell profile (`.bashrc`, `.zshrc`, etc.).

**Step 3:** Start an AI coding agent session in your ProcessWire root directory and say:
> *"Please read the `site/modules/AgentTools/agent_cli.md` file before we begin."*

**Step 4:** Describe what you want to create.

---

## CLI commands

All commands are run from the ProcessWire root directory (where `index.php` lives).

| Command | Purpose |
|---------|---------|
| `php index.php --at-cli` | Opens the agent CLI for interactive API access |
| `php index.php --at-eval 'CODE'` | Evaluate a PHP expression inline |
| `echo 'CODE' \| php index.php --at-stdin` | Evaluate multi-line PHP code from stdin |
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List migrations and their status |
| `php index.php --at-migrations-test` | Preview pending without applying |

### When to use `--at-eval` vs `--at-stdin`

`--at-eval` is convenient for simple expressions but is subject to shell escaping
rules — single quotes, double quotes, dollar signs, and backticks in the PHP code
can conflict with the shell. For anything beyond a simple one-liner, prefer
`--at-stdin` with a single-quoted heredoc, which passes PHP code verbatim:

~~~~~
cat <<'PHP' | php index.php --at-stdin
$items = $pages->find("template=blog-post, sort=-modified, limit=10");
foreach($items as $item) {
    $date = date('Y-m-d', $item->modified);
    echo "{$date} | {$item->title} | {$item->url}\n";
}
PHP
~~~~~

The single-quoted delimiter (`<<'PHP'`) prevents the shell from interpreting
`$variables`, so PHP variables pass through untouched.

---

## agent_cli.php

### Purpose

Gives the agent direct access to the ProcessWire API so it can perform any
create, update, or delete (CUD) operation, run tests, or create migration files.

### How it works

The agent may modify anything in `agent_cli.php` **after** the marker line:
~~~~~
/* ~~~ AGENT ~~~ */
~~~~~
After that marker, ProcessWire is fully booted and all API variables are
available. They are documented with PHPDoc at the top of the file.

### Notes

- If something doesn't appear to be working correctly, report the error and ask
  before attempting to fix it.
- For quick one-off operations, prefer `--at-eval` or `--at-stdin` over editing
  `agent_cli.php`.

---

## Migrations

### What is a migration?

A migration is a PHP file that makes one or more CUD changes to a ProcessWire
installation using the ProcessWire API. It is designed to be repeatable
(idempotent) and transferable across environments.

### Workflow

Always use **migration-first**: write the migration file first, apply it on the
development environment, then transfer and apply it on other environments.

1. Write the migration file in `site/assets/at/migrations/`
2. Apply it on the development environment to verify it works: `php index.php --at-migrations-apply`
3. Report the output to the user and confirm success before proceeding
4. The user transfers the file to other environments (e.g. via rsync)
5. The user applies it there via CLI or the admin UI (Setup > Agent Tools)

Do not make changes directly to the development environment and then write a
migration after the fact. Migration-first keeps all environments consistent,
and means any failure is caught on development — where it is easiest to fix —
before the migration is transferred anywhere.

### File locations

| Path | Purpose |
|------|---------|
| `site/assets/at/migrations/` | Migration PHP files — safe to rsync to other servers |

The applied migrations registry is stored in the AgentTools module configuration
in the database, so it is never at risk of being overwritten by an rsync.

### Migration file naming

~~~~~
YYYYMMDDhhmmss_name.php
~~~~~

- `YYYY` — 4-digit year
- `MM` — 2-digit month
- `DD` — 2-digit day
- `hh` — 2-digit hour (24-hour)
- `mm` — 2-digit minute
- `ss` — 2-digit second
- `name` — short description in ProcessWire page-name format (e.g. `add-blog-template`)

Unless the name is obvious from context, the agent should ask what to call it and
sanitize it with `$sanitizer->pageNameTranslate('...')`.

### Migration structure

Every migration should follow this structure:

~~~~~
<?php namespace ProcessWire;

$name = wire('at')->getMigrationName(__FILE__);
echo "# $name\n\n";

// Secondary state check (optional but recommended)
if($templates->get('blog-post')) {
    echo "- Skipped: template 'blog-post' already exists.\n";
    return;
}

// ... migration operations ...

echo "- $name has been applied\n";
~~~~~

The `getMigrationName()` function (and other helper functions) are defined in
`agent_migrate.php` and are available to all migration files at runtime.

### Dependency check

If a migration depends on a previous one having been applied, check for it
explicitly. For example, if creating a `/blog/` page that requires the `blog`
template to exist:
~~~~~
if(!$templates->get('blog')) {
    echo "- Error: the 'blog' template does not yet exist.\n";
    return;
}
~~~~~

### Output format

Migrations output plain text in markdown format. Rules:

- Start with `echo "# $name\n\n";`
- Use `- ` bullet prefix for each line of output
- Do not use `#` headings within the migration body
- End a successful migration with `echo "- $name has been applied\n";`

Example:
~~~~~
echo "- Creating field: `date`\n";
echo "- Creating template: `blog-post`\n";
echo "- $name has been applied\n";
~~~~~

### Considerations for creating migrations

- **Use the ProcessWire API** wherever possible — avoid raw SQL.
- **Never use database IDs** — IDs differ between installations. Refer to
  items by `name` (Templates, Fields, Fieldgroups, Roles, Users, Permissions)
  or by `name` + `parent` (Pages).
- **Group related operations** into a single migration file where it makes
  logical sense.
- **Migrations are forward-only** — rollback is not supported (yet).

### Applying migrations

~~~~~
php index.php --at-migrations-apply
~~~~~
