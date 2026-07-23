# AgentTools Migrations

A migration is a PHP file that makes repeatable, transferable changes to a ProcessWire installation.

## Migration-first workflow

Always write the migration first, apply it on development, then transfer to other environments.

1. Write the migration file in `site/assets/at/migrations/`
2. Apply: `php index.php --at-migrations-apply`
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

## Defensive recipes

Use these small patterns when building migrations. They keep files safe to
re-run and easier to review.

Create a field only when missing:

```php
$field = $fields->get('subtitle');
if(!$field) {
    $field = new Field();
    $field->type = $modules->get('FieldtypeText');
    $field->name = 'subtitle';
    $field->label = 'Subtitle';
    $field->save();
    echo "- Created field: subtitle\n";
} else {
    echo "- Skipped existing field: subtitle\n";
}
```

Add a field to a template in order:

```php
$template = $templates->get('blog-post');
$field = $fields->get('subtitle');
if(!$template) {
    echo "- Error: template 'blog-post' does not exist.\n";
    return;
}
if(!$field) {
    echo "- Error: field 'subtitle' does not exist.\n";
    return;
}

$fieldgroup = $template->fieldgroup;
if($fieldgroup->hasField($field)) {
    echo "- Skipped existing field on template: subtitle\n";
} else {
    $fieldgroup->add($field);
    if($fieldgroup->hasField('summary')) {
        $fieldgroup->insertAfter($field, $fieldgroup->getField('summary'));
    }
    $fieldgroup->save();
    echo "- Added field 'subtitle' to template 'blog-post'\n";
}
```

Create a template with its fieldgroup only when missing:

```php
if($templates->get('event')) {
    echo "- Skipped existing template: event\n";
} else {
    $fieldgroup = new Fieldgroup();
    $fieldgroup->name = 'event';
    $fieldgroup->add($fields->get('title'));
    $fieldgroup->add($fields->get('body'));
    $fieldgroup->save();

    $template = new Template();
    $template->name = 'event';
    $template->fieldgroup = $fieldgroup;
    $template->save();
    echo "- Created template: event\n";
}
```

Create a page only when missing under the expected parent:

```php
$parent = $pages->get('/blog/');
$template = $templates->get('blog-post');
if(!$parent->id || !$template) {
    echo "- Error: required parent or template is missing.\n";
    return;
}

$page = $pages->get("parent_id={$parent->id}, name=hello-world, include=all");
if($page->id) {
    echo "- Skipped existing page: {$page->path}\n";
} else {
    $page = new Page();
    $page->template = $template;
    $page->parent = $parent;
    $page->name = 'hello-world';
    $page->title = 'Hello World';
    $page->save();
    echo "- Created page: {$page->path}\n";
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
| `php index.php --at-migrations-apply` | Apply all pending |
| `php index.php --at-migrations-list [--json]` | Show status of all |
| `php index.php --at-migrations-test` | Preview without applying |
| `php index.php --at-migrations-lint` | Check syntax and AgentTools conventions |
| `php index.php --at-migrations-rerun --file=FILE` | Re-run one migration |

Migrations can also be applied from the admin: **Setup > Agent Tools**.

If direct PHP commands are unreliable in a Docker or similar environment, the
compatibility wrapper exposes the same modes as
`bash .agents/skills/processwire-agenttools/scripts/pw-at.sh migrations-apply`,
`migrations-list`, `migrations-test`, `migrations-lint`, and `migrations-rerun`.

Use `--at-migrations-lint` before applying newly generated migrations when you
want a cheap syntax and convention check that does not execute migration files.

## Verifying after apply

After applying, confirm the migration worked:

```bash
php index.php --at-migrations-list
```

Use `php index.php --at-migrations-list --json` when the result will be consumed
by another tool. Then spot-check the created state via CLI:

```bash
php index.php --at-eval 'echo $templates->get("event")->name . "\n";'
```

Report the output to the user before proceeding.
