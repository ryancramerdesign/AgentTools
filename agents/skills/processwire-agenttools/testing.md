# Testing

Use this only when validating or debugging the wrapper layer. It is not part of
normal AgentTools usage.

## Scope

The test script verifies wrapper behavior, not site data:

- runner detection and override handling
- inline argument transport for `eval`
- stdin transport for `stdin`
- `$variable` preservation through the wrapper
- base64 compatibility helpers
- argument validation and failure paths

It does **not** verify project-specific ProcessWire content.

## Run

From the ProcessWire project root:

```bash
bash .agents/skills/processwire-agenttools/tests/e2e-wrapper.sh
```

## Output

The script prints:

- wrapper path
- project path
- whether DDEV is available
- per-test `PASS` / `FAIL` / `SKIP`
- final summary

Exit code:

- `0` if all required tests pass
- `1` if any test fails

## When to use

- after changing the wrapper
- when a specific environment has quoting, stdin, or runner issues
- before reporting a wrapper bug

## Bug reports

Include:

- full test output
- OS / shell
- whether DDEV is in use
- any relevant env overrides such as `PW_AT_RUNNER` or `PW_AT_PHP_CMD`
