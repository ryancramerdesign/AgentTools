# AgentTools Migrations

A migration is a PHP file that makes repeatable, transferable changes to a ProcessWire installation.

## Migration-first workflow

Always write the migration first, apply it on development, then transfer to other environments.

1. Write the migration file in `site/assets/at/migrations/`
2. Apply: `bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-apply`
3. Report the output — confirm success before proceeding
4. User transfers the file to other environments (rsync, ftp, git)
5. Apply there via CLI or admin UI (**Setup > Agent Tools**)

**Never make changes directly and write a migration after.** Migration-first catches failures on development where they are easiest to fix.

## File naming

```
YYYYMMDDhhmmss_description.php
```

Description uses page-name format (lowercase, hyphens). Sanitize with `$sanitizer->pageNameTranslate('...')` if the name comes from user input. Ask the user what to name it unless obvious from context.

## Migration template

```php
<?php namespace ProcessWire;

$name = wire('at')->migrations->getName(__FILE__);
echo "# $name\n\n";

// Idempotency — skip if already done
if($templates->get('event')) {
    echo "- Skipped: template 'event' already exists.\n";
    return;
}

// Create new field
if(!$fields->get('event_date')) {
    $f = new Field();
    $f->type = $modules->get('FieldtypeDatetime');
    $f->name = 'event_date';
    $f->label = 'Event Date';
    $f->save();
    echo "- Created field: event_date\n";
}

// Create fieldgroup and template
// Every PW template requires a fieldgroup with the same name.
// Add fields to the fieldgroup, then assign it to the template.
$fg = new Fieldgroup();
$fg->name = 'event';
$fg->add($fields->get('title'));   // core field — always exists
$fg->add($fields->get('body'));    // core field — always exists
$fg->add($fields->get('event_date'));
$fg->save();

$t = new Template();
$t->name = 'event';
$t->fieldgroup = $fg;
$t->save();
echo "- Created template: event\n";

echo "- $name has been applied\n";
```

**Note:** Migration files require `<?php namespace ProcessWire;` explicitly.

## Dependency checks

If a migration depends on a previous one:

```php
if(!$templates->get('blog')) {
    echo "- Error: the 'blog' template does not yet exist.\n";
    return;
}
```

## Output format

- Start with `echo "# $name\n\n";`
- Use `- ` bullet prefix for each status line
- End with `echo "- $name has been applied\n";`
- No `#` headings within the body

## Rules

- **Use the ProcessWire API** — no raw SQL
- **Never use database IDs** — IDs differ between environments. Refer to templates, fields, roles by `name`; pages by `name` + `parent`
- **Group related operations** into a single migration
- **Migrations are forward-only** — no rollback support
- The applied registry is stored in the database (module config), not in files — safe to rsync without overwriting state

## Applying and reviewing

| Command | Purpose |
|---------|---------|
| `bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-apply` | Apply all pending |
| `bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-list` | Show status of all |
| `bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-test` | Preview without applying |

Migrations can also be applied from the admin: **Setup > Agent Tools**.

## Verifying after apply

After applying, confirm the migration worked:

```bash
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-list
```

Then spot-check the created state via CLI:

```bash
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh eval 'echo $templates->get("event")->name . "\n";'
```

Report the output to the user before proceeding.
