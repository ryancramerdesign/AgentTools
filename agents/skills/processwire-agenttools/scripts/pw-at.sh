#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  scripts/pw-at.sh eval 'PHP_CODE'
  scripts/pw-at.sh eval-b64 'BASE64_PHP_CODE'
  scripts/pw-at.sh stdin
  scripts/pw-at.sh stdin-b64 'BASE64_PHP_CODE'
  scripts/pw-at.sh cli
  scripts/pw-at.sh migrations-apply
  scripts/pw-at.sh migrations-list
  scripts/pw-at.sh migrations-test
  scripts/pw-at.sh sitemap-generate
  scripts/pw-at.sh sitemap-generate-schema

Wrapper for Ryan Cramer's AgentTools CLI.
Preserves the original AgentTools command model while selecting the correct
runtime for the current environment.

Environment overrides:
  PW_AT_RUNNER=auto|host|ddev
  PW_AT_PHP_CMD=php
EOF
}

if [[ $# -lt 1 ]]; then
  usage >&2
  exit 1
fi

mode="$1"
shift

PHP_CMD="${PW_AT_PHP_CMD:-php}"

detect_runner() {
  local forced="${PW_AT_RUNNER:-auto}"

  case "$forced" in
    auto)
      ;;
    host|ddev)
      printf '%s\n' "$forced"
      return 0
      ;;
    *)
      echo "Unsupported PW_AT_RUNNER: $forced" >&2
      exit 1
      ;;
  esac

  if [[ -d .ddev ]] && command -v ddev >/dev/null 2>&1 && ddev describe </dev/null >/dev/null 2>&1; then
    printf '%s\n' "ddev"
    return 0
  fi

  printf '%s\n' "host"
}

run_php() {
  case "$RUNNER" in
    ddev)
      ddev exec --raw -- "$PHP_CMD" index.php "$@"
      ;;
    host)
      "$PHP_CMD" index.php "$@"
      ;;
  esac
}

run_stdin() {
  case "$RUNNER" in
    ddev)
      ddev exec "$PHP_CMD" index.php --at-stdin
      ;;
    host)
      "$PHP_CMD" index.php --at-stdin
      ;;
  esac
}

normalize_statement() {
  local code="$1"
  code="${code%"${code##*[![:space:]]}"}"
  if [[ -n "$code" && "${code: -1}" != ";" ]]; then
    code="${code};"
  fi
  printf '%s' "$code"
}

# Validate arguments before detect_runner (avoids wasted ddev describe on bad input)
case "$mode" in
  eval|eval-b64)
    [[ $# -eq 1 ]] || { echo "$mode requires exactly one argument" >&2; exit 1; }
    ;;
  stdin-b64)
    [[ $# -eq 1 ]] || { echo "stdin-b64 requires exactly one base64 payload argument" >&2; exit 1; }
    ;;
  stdin|cli|migrations-apply|migrations-list|migrations-test|sitemap-generate|sitemap-generate-schema)
    [[ $# -eq 0 ]] || { echo "$mode does not accept positional arguments" >&2; exit 1; }
    ;;
  *)
    usage >&2
    exit 1
    ;;
esac

RUNNER="$(detect_runner)"

case "$mode" in
  eval)
    run_php --at-eval "$(normalize_statement "$1")"
    ;;
  eval-b64)
    decoded="$(printf '%s' "$1" | base64 --decode)"
    run_php --at-eval "$(normalize_statement "$decoded")"
    ;;
  stdin)
    run_stdin
    ;;
  stdin-b64)
    printf '%s' "$1" | base64 --decode | run_stdin
    ;;
  cli)
    run_php --at-cli
    ;;
  migrations-apply|migrations-list|migrations-test|sitemap-generate)
    run_php "--at-$mode"
    ;;
esac
