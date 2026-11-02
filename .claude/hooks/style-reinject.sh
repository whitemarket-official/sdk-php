#!/usr/bin/env bash
# Re-injects the active output style's reminder on every prompt: fights style/rule
# drift over long sessions ("system prompt repetition" baseline from "Measuring and
# Controlling Instruction (In)Stability in Language Model Dialogs", COLM 2024).
#
# Claude Code's own built-in reminder ("<style> output style is active") is a
# pointer without content: it says a style exists, not what its rules are. Exactly
# the content is what washes out. This hook fills that in.
#
# Stdout of a UserPromptSubmit hook is added to context. Generic: looks up a
# reminder file named after the effective output style; a style without one injects
# nothing, so the hook is safe to keep enabled unconditionally.
#
# Adapted from github.com/kaisa-kucherenko/claude-code-flow (MIT).

# Drain the JSON payload: unread stdin can break the pipe on large prompts.
cat >/dev/null

project_dir="${CLAUDE_PROJECT_DIR:-$PWD}"
user_dir="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"

# jq when present (correct JSON parsing); sed fallback keeps the hook
# dependency-free and portable (grep -P is GNU-only, absent on macOS).
# settings.json is machine-written, one key per line, so sed is safe enough.
read_style() {
  if command -v jq >/dev/null 2>&1; then
    jq -r '.outputStyle // empty' "$1" 2>/dev/null
  else
    sed -n 's/.*"outputStyle"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" | head -1
  fi
}

# Best-effort mirror of settings precedence (local project -> project -> user).
# Managed settings and --settings CLI overrides aren't consulted: good enough
# for a reminder that injects nothing when unsure.
get_style() {
  local f v
  for f in "$project_dir/.claude/settings.local.json" \
           "$project_dir/.claude/settings.json" \
           "$user_dir/settings.json"; do
    [ -f "$f" ] || continue
    v="$(read_style "$f")"
    [ -n "$v" ] && { printf '%s' "$v"; return; }
  done
}

style="$(get_style)"
# Style name becomes a filename component: refuse path separators.
case "$style" in *[/\\]*) exit 0 ;; esac

reminder="$project_dir/.claude/style-reminders/$style.txt"
[ -n "$style" ] && [ -f "$reminder" ] && cat "$reminder"
exit 0
