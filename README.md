# Agent Tools module for ProcessWire

Enables AI coding agents to access ProcessWire’s API. Also provides a content migration system.

## Introduction

This module provides a way for Claude Code (or other AI helpers) to have full access to 
the ProcessWire API via a command-line interface (CLI). Once connected to your site, you 
can ask Claude to create and modify pages, templates and fields, or do anything that can 
be done with the ProcessWire API. It's even possible for an entire site to be managed by 
Claude without the need for ProcessWire's admin control panel, though we're not 
suggesting that just yet.

While working with Claude Code, I asked what would be helpful for them in working with 
ProcessWire, and this module is the result. Claude needed a way to quickly access the 
ProcessWire API from the command line, and this module provides 3 distinct ways for 
Claude to do so.

Claude collaborated with me on the development of the AgentTools module, and the 
accompanying ProcessAgentTools module was developed entirely by Claude Code. Admittedly, 
part of the purpose of this module is also to help me learn AI-assisted development, as 
I'm still quite new to it, but learning quickly.

This module aims to add several agent tools over time, but this first version is also somewhat
of a proof of concept. Its first feature is basic migrations system, described further in this document.

**Please note that this module should be considered very much in 'beta test' at this stage. 
If you do use it in production (such as the migrations feature) always test locally and have 
backups of everything that can be restored easily. While I've not run into any cases where 
I had to restore anything, just the nature of the module means that you should use extra caution.**

## Requirements

- Preferably 3.0.255 or newer, but almost any 3.x version of ProcessWire should still work.
- Claude Code or another AI helper of your choice, though note we've only tested with Claude Code.

## Installation

*Any time we mention Claude Code in this document, you may optionally substitute another AI code 
helper such as Codex. But note that while this module has no Claude Code dependencies, we have 
tested exclusively with Claude Code.*

1. Place all the files for this module in a new directory: `/site/modules/AgentTools/`
2. In your ProcessWire admin, login and go to Modules > Refresh.
3. Click "Install" for the AgentTools module. ProcessAgentTools will also be installed.
4. Move on to enabling CLI for ProcessWire (below). 

### Enabling CLI for ProcessWire

Confirm that the `php` binary is accessible in your terminal/console by typing `php`. If it responds 
with "command not found" then you may need to locate where php is on your system, and then add it 
to your path. If you don't want to do that, or don't know how, you can also just tell Claude where 
your php is located when connecting Claude Code (described further down). Or it may be that Claude 
can find it for you. In my case PHP was located in /Applications/MAMP/bin/php/php8.2.26/bin which 
is quite a mouthful. I didn't want to have to ever type that in or remember it, so I added it to 
my .bash_profile path:
```
export PATH=/Applications/MAMP/bin/php/php8.2.26/bin:$PATH
```
Chances are you won't have to do anything like that though. Next, please continue with connecting 
Claude Code (below).

### Connecting Claude Code:

1. Open Claude Code in the root of your website directory.
2. Ask Claude Code to read the `/site/modules/AgentTools/CLAUDE.md` file.
3. Claude should also read the `/site/modules/AgentTools/agent_cli.md` file automatically, but maybe ask to confirm, especially if using something other than Claude Code.
4. Now you are ready to use Claude Code with ProcessWire! Test things out by asking Claude what your 3 newest pages are, or whatever suits your fancy!

### Agent skill (optional)

The module ships with an agent skill in `agents/skills/processwire-agenttools/` — a set of
markdown docs that teach AI coding agents how to use the CLI and migration system. Agents that
support the `.agents/skills/` convention will discover it automatically once installed.

To install the skill to your project root, check "Install agent skill to project" in the module
config (Modules > AgentTools) and submit. This copies the skill files to
`.agents/skills/processwire-agenttools/` in your project root, and will keep them updated
automatically on future module upgrades.

### Migrations feature

This module provides a basic migrations feature that has an AI-based workflow. The intention is that you 
would have AgentTools installed in a local development (dev server) environment with Claude Code available. There 
would be a corresponding production (live server) environment that also has the AgentTools module installed, and whether 
Claude Code is available there or not is optional. Claude Code is needed to create migrations, but not 
to apply them.

Rather than manipulating pages, templates, fields or other resources in ProcessWire's admin, you tell 
Claude to read the `/site/modules/AgentTools/CLAUDE.md` file and that you'd like to make changes that 
should be saved as migrations. For example, here's a basic prompt:

> Please create a new template named hello-world that can only be used for one page. Add the title and body 
> fields to it. Then create a new page using this new template and with the homepage as its parent. 
> Name it "hello", set the "title" to "Hello World" and add 3 html paragraphs of random placeholder/greeking 
> text as the "body". Keep it unpublished.

We've got something sneaky in that prompt "…can only be used for one page." Claude is smart, and may 
ask you questions before making the changes and creating the migration. In my case it asked me: "By one 
page, do you mean that the template should have the noParents option set to -1?" The answer is yes.

Once Claude has finished its work in your dev site you'll see it as a migration in Setup > Agent Tools. 
It should show that the migration has been already "Applied". When you are ready to apply it to your 
production server, you can copy/rsync/ftp the migrations to the production in the same directory:
`/site/assets/at/migrations/`. This is also something Claude can do if you are comfortable with it, 
and you've given Claude access to.

However the migration files are copied to the production server, they can be applied from the command 
line, or from the admin in Setup > Agent Tools. If using the admin, you'll see a list of migrations 
along with an option to apply them. If you prefer to use the command line interface, see the migrations
command reference in the CLI reference section (below). 

Once a migration has been applied, it will show as "applied" in your admin rather than "pending". 
Though note that Claude writes the migrations in a way that means they can be re-applied without issue, 
and simply report "not necessary to apply."

Tip: when asking Claude to add a new page in ProcessWire that has significant content (like a large "body") field
ask Claude if they would prefer the content in separate prompts, or all in one. When I posted a blog post,
Claude said they preferred it in separate prompts, like this:
```
name: processwire-and-ai

title: ProcessWire and AI

date: today

summary: How ProcessWire works with AI, my experience learning…

body: In this post I wanted to talk a little bit about the state of…
```
See the resulting post here: [ProcessWire and AI](https://processwire.com/blog/posts/processwire-and-ai/). 

#### Please note

- This migrations system is more experimental and not intended to replace a mature system like RockMigrations.
- File-based assets are not yet supported by migrations. 

---

## CLI reference

All commands are run from your ProcessWire root directory (where index.php lives). Note that most of these CLI commands are intended to be run by AI agents on your behalf, but are documented here for reference.

### Migration commands

| Command | Description |
|---------|-------------|
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List all migrations and their status (applied/pending) |
| `php index.php --at-migrations-test` | Preview pending migrations without applying them |

### API access commands

These commands give Claude (or any AI agent) direct access to the ProcessWire API
from the command line without needing to enter an interactive session.

| Command | Description |
|---------|-------------|
| `php index.php --at-eval 'CODE'` | Evaluate a PHP expression with full ProcessWire API access |
| `echo 'CODE' &#124; php index.php --at-stdin` | Evaluate multi-line PHP code piped from stdin |
| `php index.php --at-cli` | Open an interactive agent CLI session |

**`--at-eval` example** — ask Claude how many pages are on your site:
```
php index.php --at-eval 'echo wire()->pages->count() . " pages\n";'
```

**`--at-stdin` example** — useful for multi-line code:
```
echo '
$t = new Template();
$t->name = "hello-world";
$t->save();
echo "Created template: " . $t->name . "\n";
' | php index.php --at-stdin
```

**`--at-cli`** opens an interactive session where Claude can write code to `agent_cli.php`
and run it, with full access to all ProcessWire API variables (`$pages`, `$templates`,
`$fields`, `$modules`, etc.). See `agent_cli.md` for full details.

---

## Migration file reference

### Workflow

AgentTools uses a **migration-first** workflow:

1. Ask Claude to create a migration for the change you want
2. Claude writes the migration file and applies it on your development site
3. Claude confirms the output and that it applied successfully
4. You transfer the migration file to other environments (rsync, ftp, git, etc.)
5. Apply it there via CLI or via **Setup > Agent Tools** in the admin

Always apply and verify migrations on your development environment before
transferring them. If something goes wrong, it's much easier to fix on
development than on a live server.

### File naming

Migration files use a timestamp prefix to ensure they are always applied in the
correct order, regardless of the environment:

```
YYYYMMDDhhmmss_description.php
```

Example: `20260403155146_add-blog-post-template.php`

- `YYYY` — 4-digit year
- `MM` — 2-digit month
- `DD` — 2-digit day
- `hh` — 2-digit hour (24-hour)
- `mm` — 2-digit minute
- `ss` — 2-digit second
- `description` — short description in page-name format (lowercase, hyphens)

### File structure

Every migration follows this structure:

```php
<?php namespace ProcessWire;

$name = wire('at')->getMigrationName(__FILE__);
echo "# $name\n\n";

// Idempotency check — skip if change already exists
if($templates->get('hello-world')) {
    echo "- Skipped: template 'hello-world' already exists.\n";
    return;
}

// Make the change using the ProcessWire API
$t = new Template();
$t->name = 'hello-world';
$t->save();

echo "- Created template: hello-world\n";
echo "- $name has been applied\n";
```

**Key points:**

- All ProcessWire API variables are available (`$pages`, `$templates`, `$fields`, `$modules`, etc.)
- The idempotency check at the top means the migration can be safely re-run — if the
  change already exists it skips gracefully rather than erroring out
- Use `echo` statements to report what the migration did; this output is shown in
  both the CLI and the admin UI
- Always use names to refer to templates, fields, and pages — never database IDs,
  as IDs differ between environments
- The applied migrations registry is stored in the database (not in a file), so it
  is never overwritten when you rsync migration files to a server

---

## Community resources

- **[processwire-ai-docs](https://github.com/gebeer/processwire-ai-docs)** by gebeer — A collection of AI agent skills for ProcessWire, including an AgentTools skill with a DDEV wrapper script (`pw-at.sh`) that automatically routes `--at-*` commands into the DDEV container when appropriate.

