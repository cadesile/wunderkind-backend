#!/bin/bash
#
# generate_project_context_push.sh
# Generates project context, commits it if changed, then pushes.
#
# Usage:
#   bash scripts/generate_project_context_push.sh [<remote>] [<branch>] [<git-push-flags>]
#
# Examples:
#   bash scripts/generate_project_context_push.sh                   # generate, commit, push current branch
#   bash scripts/generate_project_context_push.sh origin master     # push to specific remote/branch
#   bash scripts/generate_project_context_push.sh origin main --force-with-lease
#
# Git alias (set up once):
#   git config alias.pushc '!bash scripts/generate_project_context_push.sh'
#   → then: git pushc origin master

set -e

# ── Colors ────────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()    { echo -e "${BLUE}▸ $1${NC}"; }
success() { echo -e "${GREEN}✓ $1${NC}"; }
warn()    { echo -e "${YELLOW}⚠ $1${NC}"; }

# ── Context generation ─────────────────────────────────────────────────────────
CONTEXT_SCRIPT="scripts/generate_project_context.sh"

if [ ! -f "$CONTEXT_SCRIPT" ]; then
    warn "generate_project_context.sh not found at ${CONTEXT_SCRIPT} — skipping context generation."
else
    info "Generating project context..."
    bash "$CONTEXT_SCRIPT"

    # Determine the output path (mirrors generate_project_context.sh defaults)
    REPO_NAME=$(basename "$PWD")
    CONTEXT_FILE="docs/${REPO_NAME}-context.md"

    if [ ! -f "$CONTEXT_FILE" ]; then
        warn "Expected context file not found at ${CONTEXT_FILE} — skipping commit."
    else
        # Only commit if the file actually changed (new or modified)
        if git ls-files --error-unmatch "$CONTEXT_FILE" &>/dev/null && git diff --quiet "$CONTEXT_FILE"; then
            info "Context file unchanged — skipping commit."
        else
            info "Staging and committing context file..."
            git add "$CONTEXT_FILE"

            if ! git diff --cached --quiet; then
                git commit -m "chore: update project context [skip ci]"
                success "Context committed."
            else
                info "Nothing to commit."
            fi
        fi
    fi
fi

# ── Push ──────────────────────────────────────────────────────────────────────
info "Pushing${*:+ ($*)}..."
git push "$@"
success "Push complete."
