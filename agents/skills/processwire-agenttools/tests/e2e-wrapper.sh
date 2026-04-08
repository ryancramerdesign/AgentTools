#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$SKILL_DIR/../../.." && pwd)"
WRAPPER="$SKILL_DIR/scripts/pw-at.sh"

PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

have_ddev() {
  [[ -d "$PROJECT_ROOT/.ddev" ]] && command -v ddev >/dev/null 2>&1 && ddev describe </dev/null >/dev/null 2>&1
}

b64() {
  printf '%s' "$1" | base64 | tr -d '\n'
}

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
  printf 'PASS %s\n' "$1"
}

fail() {
  FAIL_COUNT=$((FAIL_COUNT + 1))
  printf 'FAIL %s\n' "$1"
  printf '  %s\n' "$2"
}

skip() {
  SKIP_COUNT=$((SKIP_COUNT + 1))
  printf 'SKIP %s\n' "$1"
  printf '  %s\n' "$2"
}

run_cmd() {
  local name="$1"
  shift

  local stdout_file="$TMP_DIR/${name}.out"
  local stderr_file="$TMP_DIR/${name}.err"

  set +e
  "$@" >"$stdout_file" 2>"$stderr_file"
  local status=$?
  set -e

  RUN_STATUS=$status
  RUN_STDOUT="$(cat "$stdout_file")"
  RUN_STDERR="$(cat "$stderr_file")"
}

assert_success_stdout() {
  local name="$1"
  local expected="$2"

  if [[ $RUN_STATUS -ne 0 ]]; then
    fail "$name" "expected exit 0, got $RUN_STATUS; stderr: $RUN_STDERR"
    return
  fi

  if [[ "$RUN_STDOUT" != "$expected" ]]; then
    fail "$name" "expected stdout [$expected], got [$RUN_STDOUT]"
    return
  fi

  pass "$name"
}

assert_failure_contains() {
  local name="$1"
  local expected="$2"

  if [[ $RUN_STATUS -eq 0 ]]; then
    fail "$name" "expected non-zero exit, got 0"
    return
  fi

  if [[ "$RUN_STDERR" != *"$expected"* ]]; then
    fail "$name" "expected stderr to contain [$expected], got [$RUN_STDERR]"
    return
  fi

  pass "$name"
}

printf 'Wrapper: %s\n' "$WRAPPER"
printf 'Project: %s\n' "$PROJECT_ROOT"
if have_ddev; then
  printf 'Runner: ddev available\n'
else
  printf 'Runner: ddev unavailable\n'
fi

cd "$PROJECT_ROOT"

run_cmd eval_basic bash "$WRAPPER" eval 'echo "EVAL_OK\n";'
assert_success_stdout eval_basic "EVAL_OK"

run_cmd eval_vars bash "$WRAPPER" eval '$x = "EVAL_VAR_OK"; echo $x, "\n";'
assert_success_stdout eval_vars "EVAL_VAR_OK"

run_cmd stdin_basic bash -lc "printf '%s' 'echo \"STDIN_OK\\n\";' | bash '$WRAPPER' stdin"
assert_success_stdout stdin_basic "STDIN_OK"

run_cmd stdin_vars bash -lc "printf '%s' '\$x = \"STDIN_VAR_OK\"; echo \$x, \"\\n\";' | bash '$WRAPPER' stdin"
assert_success_stdout stdin_vars "STDIN_VAR_OK"

run_cmd eval_b64 bash "$WRAPPER" eval-b64 "$(b64 'echo "EVAL_B64_OK\n";')"
assert_success_stdout eval_b64 "EVAL_B64_OK"

run_cmd stdin_b64 bash "$WRAPPER" stdin-b64 "$(b64 'echo "STDIN_B64_OK\n";')"
assert_success_stdout stdin_b64 "STDIN_B64_OK"

run_cmd invalid_runner env PW_AT_RUNNER=invalid bash "$WRAPPER" eval 'echo 1;'
assert_failure_contains invalid_runner "Unsupported PW_AT_RUNNER: invalid"

run_cmd stdin_extra_arg bash "$WRAPPER" stdin extra
assert_failure_contains stdin_extra_arg "stdin does not accept positional arguments"

run_cmd eval_missing_arg bash "$WRAPPER" eval
assert_failure_contains eval_missing_arg "eval requires exactly one argument"

if have_ddev; then
  run_cmd forced_ddev env PW_AT_RUNNER=ddev bash "$WRAPPER" eval 'echo "DDEV_OK\n";'
  assert_success_stdout forced_ddev "DDEV_OK"
else
  skip forced_ddev "ddev not available in this project"
fi

printf '\nSummary: %d passed, %d failed, %d skipped\n' "$PASS_COUNT" "$FAIL_COUNT" "$SKIP_COUNT"

if [[ $FAIL_COUNT -ne 0 ]]; then
  exit 1
fi
