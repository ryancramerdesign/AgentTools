# Agent Tools module for ProcessWire

Enables AI coding agents to access ProcessWire’s API. Also provides a content migration system.

## Introduction

This module provides a way for Claude Code (or other AI helpers) to have full access to 
the ProcessWire API via a command-line interface (CLI). Once connected to your site, you 
can ask Claude to create and modify pages, templates and fields, or do anything that can 
be done with the ProcessWire API. It's even possible for an entire site to be managed by 
Claude without the need for ProcessWire's admin control panel, though we're not 
suggesting that just yet.

Claude collaborated with me on the development of the AgentTools module, and the
accompanying ProcessAgentTools module was developed entirely by Claude Code. 

### Command line tools for AI agents

While working with Claude Code, I asked what would be helpful for them in working with 
ProcessWire, and this module is the result. Claude needed a way to quickly access the 
ProcessWire API from the command line, and this module provides 3 distinct ways for 
Claude to do so. AI agents can also use the command line to create migrations, generate 
JSON sitemaps that provide an overview of the entire ProcessWire installation, install
AI agent skills into your ProcessWire installation. Further, AI agents connected through
the command line interface (CLI) can do anything that the ProcessWire API can do. 

### Admin tools for you

Also packaged with the AgentTools module is the ProcessAgentTools module. This provides an
admin application (Setup > Agent Tools), currently with the following features:

- **Engineer**: A natural language AI interface to your site. Ask questions, request changes,
  or have it create migrations — all from your browser. The Engineer has four tools available
  to it: `eval_php` (query live site data), `save_migration` (create a migration for review),
  `site_info` (fetch the site's page tree or fields/templates schema on demand), and
  `api_docs` (discover and retrieve ProcessWire API documentation on demand). The Engineer
  supports conversation memory so it can refer back to earlier exchanges in the same session.
  Multiple AI providers and models can be configured and switched between from a Control room
  in the Engineer form.

- **Migrations**: This tool enables you to create, apply, list, view, and delete migrations
  that were created by the Engineer or by your AI agent using the command line tools of
  this module.

**Please note that this module should be considered very much in 'beta test' at this stage. 
If use any of its features in production, test thoroughly in a dev environment first, and keep
backups of everything that can be restored easily. While I've not run into any cases where 
I had to restore anything, just the nature of the module means that you should use extra caution.**

## Requirements

ProcessWire 3.0.255 or newer is recommended, but almost any 3.x version of ProcessWire should still work.
If you use ProcessWire 3.0.258 or newer, the Engineer becomes smarter when working with fields,
as ProcessWire 3.0.258 and newer include API.md files that the Engineer retrieves on demand via
its `api_docs` tool. More API.md files covering other parts of ProcessWire are being added over time.

CLI-compatable AI helper of your choice in order to use the CLI tools to full effect. Examples 
include Claude Code and OpenAI Codex, though it should work with others as well. This module
has primarily been developed with and tested with Claude Code.

An Anthropic (Claude) API key, OpenAI API key, or any OpenAI compatible API key is required to use the 
Engineer feature of the included admin helper module (ProcessAgentTools). When using an Anthropic API key,
this module automatically uses prompt caching with a 1-hour TTL, making it very efficient and
economical when handling multiple Engineer requests. 

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

### Connecting your AI agent to the command line interface (CLI)

1. Open Claude Code or other AI agent in the root of your website directory.
2. Ask your AI agent to read the `/site/modules/AgentTools/AGENTS.md` or  `/site/modules/AgentTools/CLAUDE.md`file.
3. Your AI agent should also read the `/site/modules/AgentTools/agent_cli.md` file automatically, but maybe ask to confirm, especially if using something other 
   than Claude Code.
4. Now you are ready to use Claude Code or other AI agent with ProcessWire! Test things out by asking Claude what your 3 newest pages are, or whatever suits 
   your fancy!

### Agent skill (optional)

The module ships with an agent skill in `agents/skills/processwire-agenttools/` — a set of
markdown docs that teach AI coding agents how to use the CLI and migration system. Agents that
support the `.agents/skills/` convention will discover it automatically once installed.

To install the skill to your project root, check "Install agent skill to project" in the module
config (Modules > AgentTools) and submit. This copies the skill files to
`.agents/skills/processwire-agenttools/` in your project root, and will keep them updated
automatically on future module upgrades.

### Migrations feature

This module provides a migrations feature that has an AI-based workflow. Two different kinds of workflows are available:

1. You can have AgentTools installed in a local development (dev server) environment with Claude Code or other AI agent available. There 
   would be a corresponding production (live server) environment that also has the AgentTools module installed. Whether 
   an AI agent is available there or not is optional. Claude Code (or other CLI AI agent tool) is needed to create migrations, but not 
   to apply them.

2. You can also create migrations directly from the admin, available from Setup > Agent Tools > Migrations.
   In order to do this, you must have an Anthropic, OpenAI or OpenAI compatible API key populated in the AgentTools module settings.

Either method words to create migrations, but the first method (CLI like Code Claude) generally has more context and also has
the ability to ask you follow-up questions if it's not clear about anything. 

Here is an example of a basic prompt that you might use to create a migration:

> Please create a new template named hello-world that can only be used for one page. Add the title and body 
> fields to it. Then create a new page using this new template and with the homepage as its parent. 
> Name it "hello", set the "title" to "Hello World" and add 3 html paragraphs of random placeholder/greeking 
> text as the "body". Keep it unpublished.

We've got something sneaky in that prompt "…can only be used for one page." Claude is smart, and may 
ask you questions before making the changes and creating the migration. In my case it asked me: "By one 
page, do you mean that the template should have the noParents option set to -1?" The answer is yes.

Whether using the CLI or the admin, once your AI agent has finished its work in your dev site you'll 
see it as a migration in Setup > Agent Tools. When you are ready to apply it, click the Apply button.
Confirm that it worked correctly, and then you can copy/rsync/ftp the migrations to the production server 
in the same directory: `/site/assets/at/migrations/`. This is also something some AI agents can do if you 
are comfortable with it, and you've given them access to.

However the migration files are copied to the production server, they can be applied from the command 
line, or from the admin in Setup > Agent Tools. If using the admin, you'll see a list of migrations 
along with an option to apply them. If you prefer to use the command line interface, see the migrations
command reference in the CLI reference section (below). 

Once a migration has been applied, it will show as "applied" in your admin rather than "pending". 
Though note that the AI agent should write the migrations in a way that means they can be re-applied without issue, 
and simply report "not necessary to apply."

Tip: when asking an AI agent to add a new page in ProcessWire that has significant content (like a large "body") field
ask them if they would prefer the content in separate prompts, or all in one. When I posted a blog post,
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

- This migrations system is somewhat experimental and not intended to replace a mature system like RockMigrations.
- File-based assets are not yet supported by migrations. 

---

## CLI reference

All commands are run from your ProcessWire root directory (where `index.php` lives). Note that most of these CLI commands are intended to be run by AI agents on your behalf, but are documented here for reference.

### Migration commands

| Command | Description |
|---------|-------------|
| `php index.php --at-migrations-apply` | Apply all pending migrations |
| `php index.php --at-migrations-list` | List all migrations and their status (applied/pending) |
| `php index.php --at-migrations-test` | Preview pending migrations without applying them |

### Site map commands

The site map gives AI agents a complete JSON overview of your ProcessWire installation —
templates, fields, pages, and modules — so they can answer questions and create accurate
migrations without querying the database on every request.

| Command | Description |
|---------|-------------|
| `php index.php --at-sitemap-generate` | Generate a site map to `site/assets/at/site-map.json` |
| `php index.php --at-sitemap-generate-schema` | Generate a schema map to `site/assets/at/site-map-schema.json` |

Run `--at-sitemap-generate` at the start of a session on an unfamiliar site. Run
`--at-sitemap-generate-schema` when you need full field configuration details, per-template
field context overrides, or detailed template settings — useful when creating migrations
that depend on existing configuration. The admin Engineer regenerates these automatically
after applying migrations.

### API access commands

These commands give your AI agent direct access to the ProcessWire API
from the command line without needing to enter an interactive session.

| Command | Description |
|---------|-------------|
| `php index.php --at-eval 'CODE'` | Evaluate a PHP expression with full ProcessWire API access |
| `echo 'CODE' \| php index.php --at-stdin` | Evaluate multi-line PHP code piped from stdin |
| `php index.php --at-cli` | Open an interactive agent CLI session |

**`--at-eval` example** — ask your AI agent how many pages are on your site:
```
php index.php --at-eval 'echo wire()->pages->count() . " pages\n";'
```

**`--at-stdin` example** — useful for multi-line code:
```
cat <<'PHP' | php index.php --at-stdin
$t = new Template();
$t->name = "hello-world";
$t->save();
echo "Created template: " . $t->name . "\n";
PHP
```

**`--at-cli`** opens an interactive session where your AI agent can write code to `agent_cli.php`
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

$name = wire('at')->migrations->getName(__FILE__);
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
- **[processwire-boost](https://github.com/trk/processwire-boost)** by trk — An AI context bridge for ProcessWire that compiles guidelines and skill playbooks for 9 AI agents (Claude Code, Cursor, Copilot, Gemini, and more), generates a static schema map, and provides a live MCP server with 28 tools for querying and modifying a ProcessWire site.

