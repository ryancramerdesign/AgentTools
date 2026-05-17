# AgentTools CLI

## Default interface

Run AgentTools commands directly from the ProcessWire root directory, where
`index.php` lives:

```bash
php index.php --at-eval 'CODE'
echo 'CODE' | php index.php --at-stdin
php index.php --at-cli
```

The `scripts/pw-at.sh` wrapper is only a compatibility layer for Docker or
similar environments where direct PHP commands are unreliable because of DDEV,
container selection, or command transport. Use the wrapper in those environments,
or when debugging wrapper behavior.

Wrapper-only compatibility helpers:

- `eval-b64` for inline code when the execution environment rewrites `$variables`
- `stdin-b64` for multi-line code when command transport is unreliable

## --at-eval

Evaluate a single PHP expression. The `ProcessWire` namespace is injected automatically — do not add it.

```bash
php index.php --at-eval 'echo wire()->pages->count() . " pages\n";'
```

**Use only for simple one-liners.** Shell escaping rules apply — single quotes, double quotes, `$`, and backticks in the PHP code can conflict with the shell.

Direct PHP commands are preferred when they work in the current environment:

```bash
php index.php --at-eval 'echo wire()->pages->count() . " pages\n";'
php index.php --at-eval 'foreach(wire("pages")->find("limit=2") as $p) echo $p->id . "\n";'
```

If the command runner rewrites `$p` before PHP receives it, switch to
`--at-stdin` with a single-quoted heredoc. In Docker-like environments where that
transport still fails, use the wrapper's `eval-b64` mode.

## --at-stdin

Evaluate multi-line PHP from stdin. Use a **single-quoted heredoc** (`<<'PHP'`) to prevent the shell from expanding PHP `$variables`:

```bash
cat <<'PHP' | php index.php --at-stdin
$items = $pages->find("template=blog-post, sort=-modified, limit=10");
foreach($items as $item) {
    $date = date('Y-m-d', $item->modified);
    echo "{$date} | {$item->title} | {$item->url}\n";
}
PHP
```

Without the single-quoted delimiter, bash expands `$items`, `$item`, etc. before PHP sees them — causing silent failures or empty output.

The `ProcessWire` namespace is injected automatically, same as `--at-eval`.

**Prefer `--at-stdin` over `--at-eval`** for anything beyond a trivial one-liner.

If stdin snippets containing `$variables` are being rewritten by the command
runner or tool transport, send the code as base64 instead:

```bash
bash .agents/skills/processwire-agenttools/scripts/pw-at.sh stdin-b64 'BASE64_PAYLOAD'
```

Generate the payload with a local encoder and pass the result to the wrapper as a
single argument. This is a wrapper-specific fallback, not normal AgentTools usage.

## --at-cli

Opens an interactive session using `agent_cli.php` in the AgentTools module directory. Edit only the code **after** the marker:

```php
/* ~~~ AGENT ~~~ */
```

Everything above the marker is bootstrap code — do not modify it. All API variables listed in SKILL.md are available below the marker.

Then run:

```bash
php index.php --at-cli
```

Each run executes the file once — state does not persist between runs. Use for extended multi-step operations where you update the code and re-run.

## When to use which

| Scenario | Command |
|----------|---------|
| Quick data lookup, simple expression | `eval` |
| Multi-line code, anything with `$` or quotes | `stdin` with heredoc |
| Extended multi-step session | `cli` |

## Common mistakes

- Using `--at-eval` for complex code — shell escaping silently corrupts the PHP
- Reaching for the wrapper before trying the authoritative `php index.php --at-*` commands
- In Docker-like environments, continuing to use direct commands after command transport or DDEV/container selection has proven unreliable
- Using plain wrapper `eval` or `stdin` in an environment that rewrites `$variables` in command strings instead of switching to `eval-b64` or `stdin-b64`
- Passing a bare expression to `eval` and expecting printed output — write `echo $pages->count();` instead
- Using a **double-quoted** heredoc (`<<PHP` instead of `<<'PHP'`) — bash expands `$variables`
- Adding `namespace ProcessWire;` in eval/stdin code — it is injected automatically and doubling it causes errors
- If behavior is unexpected, capture the exact command and output first. For destructive or ambiguous fixes, ask before proceeding
