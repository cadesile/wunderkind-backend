#!/bin/bash
#
# generate_project_context.sh
# Drop into any project root — auto-detects stack, uses Claude or Gemini to generate context.
#
# Usage:
#   bash scripts/generate_project_context.sh [--no-ai] [--ai <claude|gemini>] [--output-dir <dir>] [--depth <n>] [--debug-detection]
#
# Requirements: jq
# Optional:     Claude Code CLI (claude) or Gemini CLI (gemini) — enables AI-generated summaries

set -e

# ── Argument parsing ──────────────────────────────────────────────────────────
USE_AI=true
AI_CLI="claude"
OUTPUT_DIR="docs"
TREE_DEPTH=3
DEBUG_DETECTION=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-ai)            USE_AI=false ;;
        --ai)               AI_CLI="$2"; shift ;;
        --output-dir)       OUTPUT_DIR="$2"; shift ;;
        --depth)            TREE_DEPTH="$2"; shift ;;
        --debug-detection)  DEBUG_DETECTION=true ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

START_TIME=$(date +%s)

# ── Config ────────────────────────────────────────────────────────────────────
REPO_NAME=$(basename "$PWD")
OUTPUT_FILE="${OUTPUT_DIR}/${REPO_NAME}-context.md"
TEMP_FILE="${OUTPUT_FILE}.tmp"

# ── Colors ────────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()    { echo -e "${BLUE}▸ $1${NC}" >&2; }
success() { echo -e "${GREEN}✓ $1${NC}" >&2; }
warn()    { echo -e "${YELLOW}⚠ $1${NC}" >&2; }

# ── Dependency checks ─────────────────────────────────────────────────────────
if ! command -v jq &>/dev/null; then
    warn "jq not found — JSON parsing will be limited. Install: brew install jq"
    HAS_JQ=false
else
    HAS_JQ=true
fi

# ── AI CLI check ──────────────────────────────────────────────────────────────
if [ "$USE_AI" = true ]; then
    if [ -n "$CLAUDECODE" ] && [ "$AI_CLI" = "claude" ]; then
        info "Running inside a Claude Code session — AI summaries skipped (nested sessions not supported)."
        USE_AI=false
    elif command -v "$AI_CLI" &>/dev/null; then
        info "${AI_CLI} CLI detected — AI summaries enabled."
    else
        info "${AI_CLI} CLI not found — AI summaries skipped. Install ${AI_CLI} to enable."
        USE_AI=false
    fi
fi

# ── AI CLI helper ─────────────────────────────────────────────────────────────
# Uses either `claude -p` or `gemini -p` (print mode).
call_ai() {
    local prompt="$1"
    [ "$USE_AI" = false ] && echo "" && return 0
    local result
    if [ "$AI_CLI" = "gemini" ]; then
        result=$(gemini -p "$prompt" 2>/dev/null)
    else
        result=$(claude -p "$prompt" 2>/dev/null)
    fi
    [ -z "$result" ] && warn "$AI_CLI returned empty for: ${prompt:0:60}..."
    echo "$result"
}

# ── Tech stack detection ──────────────────────────────────────────────────────
info "Detecting tech stack..."

STACK_PHP=false;    STACK_SYMFONY=false; STACK_LARAVEL=false
STACK_NODE=false;   STACK_NEXT=false;    STACK_EXPRESS=false
STACK_PYTHON=false; STACK_DJANGO=false;  STACK_FASTAPI=false; STACK_FLASK=false
STACK_GO=false;     STACK_RUST=false;    STACK_RUBY=false;    STACK_RAILS=false

PRIMARY_LANG="unknown"; PRIMARY_FRAMEWORK="unknown"
SOURCE_DIR="src"; MODELS_DIR=""; CONTROLLERS_DIR=""; SERVICES_DIR=""
PRIMARY_EXT="txt"

# Runs detection in whatever directory is current (repo root or a subdir).
detect_stack() {
    local dir="${1:-.}"

    if [ -f "${dir}/composer.json" ]; then
        STACK_PHP=true; PRIMARY_LANG="php"; PRIMARY_EXT="php"
        SOURCE_DIR="${dir}/src"; SERVICES_DIR="${dir}/src/Service"
        if [ "$HAS_JQ" = true ]; then
            if jq -e '.require | has("symfony/framework-bundle")' "${dir}/composer.json" &>/dev/null; then
                STACK_SYMFONY=true; PRIMARY_FRAMEWORK="symfony"
                MODELS_DIR="${dir}/src/Entity"; CONTROLLERS_DIR="${dir}/src/Controller"
            elif jq -e '.require | has("laravel/framework")' "${dir}/composer.json" &>/dev/null; then
                STACK_LARAVEL=true; PRIMARY_FRAMEWORK="laravel"
                MODELS_DIR="${dir}/app/Models"; CONTROLLERS_DIR="${dir}/app/Http/Controllers"; SERVICES_DIR="${dir}/app/Services"
            else
                PRIMARY_FRAMEWORK="php"; MODELS_DIR="${dir}/src"; CONTROLLERS_DIR="${dir}/src"
            fi
        fi
    fi

    if [ -f "${dir}/package.json" ]; then
        STACK_NODE=true
        [ "$PRIMARY_LANG" = "unknown" ] && PRIMARY_LANG="node"
        PRIMARY_EXT="ts"
        [ ! -d "${dir}/src" ] && SOURCE_DIR="${dir}/app"
        if [ "$HAS_JQ" = true ]; then
            if jq -e '.dependencies | has("next")' "${dir}/package.json" &>/dev/null; then
                STACK_NEXT=true; PRIMARY_FRAMEWORK="nextjs"
                MODELS_DIR="${dir}/app/models"; CONTROLLERS_DIR="${dir}/app/api"; SERVICES_DIR="${dir}/app/services"
            elif jq -e '.dependencies | has("express")' "${dir}/package.json" &>/dev/null; then
                STACK_EXPRESS=true; PRIMARY_FRAMEWORK="express"
                MODELS_DIR="${dir}/src/models"; CONTROLLERS_DIR="${dir}/src/controllers"; SERVICES_DIR="${dir}/src/services"
            fi
        fi
    fi

    for pyfile in requirements.txt pyproject.toml setup.py; do
        if [ -f "${dir}/${pyfile}" ]; then
            STACK_PYTHON=true
            [ "$PRIMARY_LANG" = "unknown" ] && PRIMARY_LANG="python"
            PRIMARY_EXT="py"; SOURCE_DIR="${dir}"
            if grep -qi "django" "${dir}/${pyfile}" 2>/dev/null; then
                STACK_DJANGO=true; PRIMARY_FRAMEWORK="django"
                MODELS_DIR="${dir}/*/models.py"; CONTROLLERS_DIR="${dir}/*/views.py"; SERVICES_DIR="${dir}/*/services"
            elif grep -qi "fastapi" "${dir}/${pyfile}" 2>/dev/null; then
                STACK_FASTAPI=true; PRIMARY_FRAMEWORK="fastapi"
                MODELS_DIR="${dir}/app/models"; CONTROLLERS_DIR="${dir}/app/routers"; SERVICES_DIR="${dir}/app/services"
            elif grep -qi "flask" "${dir}/${pyfile}" 2>/dev/null; then
                STACK_FLASK=true; PRIMARY_FRAMEWORK="flask"
                MODELS_DIR="${dir}/app/models"; CONTROLLERS_DIR="${dir}/app/routes"; SERVICES_DIR="${dir}/app/services"
            fi
            break
        fi
    done

    if [ -f "${dir}/go.mod" ]; then
        STACK_GO=true; PRIMARY_LANG="go"; PRIMARY_FRAMEWORK="go"; PRIMARY_EXT="go"
        SOURCE_DIR="${dir}"; MODELS_DIR="${dir}/internal/model"; CONTROLLERS_DIR="${dir}/internal/handler"; SERVICES_DIR="${dir}/internal/service"
    fi

    if [ -f "${dir}/Cargo.toml" ]; then
        STACK_RUST=true; PRIMARY_LANG="rust"; PRIMARY_FRAMEWORK="rust"; PRIMARY_EXT="rs"
        SOURCE_DIR="${dir}/src"; MODELS_DIR="${dir}/src/models"; CONTROLLERS_DIR="${dir}/src/handlers"; SERVICES_DIR="${dir}/src/services"
    fi

    if [ -f "${dir}/Gemfile" ]; then
        STACK_RUBY=true; PRIMARY_LANG="ruby"; PRIMARY_EXT="rb"
        if grep -qi "rails" "${dir}/Gemfile" 2>/dev/null; then
            STACK_RAILS=true; PRIMARY_FRAMEWORK="rails"
            MODELS_DIR="${dir}/app/models"; CONTROLLERS_DIR="${dir}/app/controllers"; SERVICES_DIR="${dir}/app/services"
        fi
    fi
}

# First pass: try repo root
detect_stack "."

# If stack still unknown, prompt user for the app subdirectory
if [ "$PRIMARY_LANG" = "unknown" ]; then
    warn "Could not detect a recognised stack in the repo root (no composer.json, package.json, go.mod, etc.)."
    echo ""
    printf "  Is the app code in a subdirectory? Enter the path (e.g. 'deploy', 'app', 'backend')\n"
    printf "  or press Enter to continue without stack detection: "
    read -r APP_SUBDIR
    if [ -n "$APP_SUBDIR" ] && [ -d "$APP_SUBDIR" ]; then
        info "Retrying detection in '${APP_SUBDIR}'..."
        detect_stack "$APP_SUBDIR"
        if [ "$PRIMARY_LANG" = "unknown" ]; then
            warn "Still could not detect a stack in '${APP_SUBDIR}'. Continuing with limited context."
        else
            success "Stack detected in '${APP_SUBDIR}': ${PRIMARY_FRAMEWORK} (${PRIMARY_LANG})"
        fi
    elif [ -n "$APP_SUBDIR" ]; then
        warn "Directory '${APP_SUBDIR}' not found. Continuing with limited context."
    fi
fi

# ── Dev environment detection ─────────────────────────────────────────────────
DEV_ENV="bare"
LANDO_RECIPE=""; LANDO_PHP=""; LANDO_DB=""

if [ -f ".lando.yml" ] || [ -f ".lando.base.yml" ]; then
    DEV_ENV="lando"
    LANDO_FILE=".lando.yml"; [ -f ".lando.base.yml" ] && LANDO_FILE=".lando.base.yml"
    LANDO_RECIPE=$(grep -m1 'recipe:' "$LANDO_FILE" 2>/dev/null | awk '{print $2}' || echo "")
    LANDO_PHP=$(grep -m1 'php:' "$LANDO_FILE" 2>/dev/null | awk '{print $2}' | tr -d "'" || echo "")
    LANDO_DB=$(grep -m1 'database:' "$LANDO_FILE" 2>/dev/null | awk '{print $2}' | tr -d "'" || echo "")
elif [ -f "docker-compose.yml" ] || [ -f "compose.yaml" ] || [ -f "docker-compose.yaml" ]; then
    DEV_ENV="docker"
elif [ -f ".devcontainer/devcontainer.json" ]; then
    DEV_ENV="devcontainer"
elif [ -f "Makefile" ] && grep -q "^dev\|^up\|^start" Makefile 2>/dev/null; then
    DEV_ENV="make"
fi

case "$DEV_ENV" in
    lando)  case "$PRIMARY_LANG" in php) RUN_PREFIX="lando php" ;; node) RUN_PREFIX="lando node" ;; *) RUN_PREFIX="lando" ;; esac ;;
    docker) RUN_PREFIX="docker compose exec app" ;;
    *)      RUN_PREFIX="" ;;
esac

case "$PRIMARY_FRAMEWORK" in
    symfony) CONSOLE_CMD="${RUN_PREFIX} bin/console" ;;
    laravel) CONSOLE_CMD="${RUN_PREFIX} artisan" ;;
    django)  CONSOLE_CMD="${RUN_PREFIX} manage.py" ;;
    rails)   CONSOLE_CMD="${RUN_PREFIX} rails" ;;
    *)       CONSOLE_CMD="${RUN_PREFIX}" ;;
esac

# ── Database detection ────────────────────────────────────────────────────────
DB_HINTS=""
for file in composer.json package.json requirements.txt pyproject.toml .env .lando.yml; do
    # Prefer APP_SUBDIR if set and the file exists there, otherwise fall back to repo root
    [ -n "$APP_SUBDIR" ] && [ -f "${APP_SUBDIR}/${file}" ] && file="${APP_SUBDIR}/${file}"
    [ -f "$file" ] || continue
    grep -qi "mysql"    "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}MySQL "
    grep -qi "postgres" "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}PostgreSQL "
    grep -qi "mongodb"  "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}MongoDB "
    grep -qi "sqlite"   "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}SQLite "
    grep -qi "redis"    "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}Redis "
done
DB_HINTS=$(echo "$DB_HINTS" | tr ' ' '\n' | sort -u | grep -v '^$' | tr '\n' ' ' | xargs)

# ── Version extraction ────────────────────────────────────────────────────────
# APP_DIR: the directory where the stack was found (may differ from repo root)
APP_DIR="."
[ -n "$APP_SUBDIR" ] && [ -d "$APP_SUBDIR" ] && [ "$PRIMARY_LANG" != "unknown" ] && APP_DIR="$APP_SUBDIR"

FRAMEWORK_VERSION=""; PHP_VERSION=""; NODE_VERSION=""
if [ "$HAS_JQ" = true ]; then
    if [ -f "${APP_DIR}/composer.json" ]; then
        PHP_VERSION=$(jq -r '.require.php // ""' "${APP_DIR}/composer.json" 2>/dev/null | tr -d '>=^~< ')
        [ "$PRIMARY_FRAMEWORK" = "symfony" ] && \
            FRAMEWORK_VERSION=$(jq -r '.require["symfony/framework-bundle"] // ""' "${APP_DIR}/composer.json" 2>/dev/null | tr -d '>=^~<*. ')
        [ "$PRIMARY_FRAMEWORK" = "laravel" ] && \
            FRAMEWORK_VERSION=$(jq -r '.require["laravel/framework"] // ""' "${APP_DIR}/composer.json" 2>/dev/null | tr -d '>=^~< ')
    fi
    if [ -f "${APP_DIR}/package.json" ]; then
        NODE_VERSION=$(jq -r '.engines.node // ""' "${APP_DIR}/package.json" 2>/dev/null | tr -d '>=^~< ')
        [ "$PRIMARY_FRAMEWORK" = "nextjs" ] && \
            FRAMEWORK_VERSION=$(jq -r '.dependencies.next // ""' "${APP_DIR}/package.json" 2>/dev/null | tr -d '>=^~< ')
    fi
fi

# ── Debug detection dump ──────────────────────────────────────────────────────
if [ "$DEBUG_DETECTION" = true ]; then
    echo ""
    echo "=== Detection Results ==="
    echo "REPO_NAME         : $REPO_NAME"
    echo "AI_CLI            : $AI_CLI"
    echo "PRIMARY_LANG      : $PRIMARY_LANG"
    echo "PRIMARY_FRAMEWORK : $PRIMARY_FRAMEWORK"
    echo "FRAMEWORK_VERSION : $FRAMEWORK_VERSION"
    echo "PHP_VERSION       : $PHP_VERSION"
    echo "NODE_VERSION      : $NODE_VERSION"
    echo "SOURCE_DIR        : $SOURCE_DIR"
    echo "MODELS_DIR        : $MODELS_DIR"
    echo "CONTROLLERS_DIR   : $CONTROLLERS_DIR"
    echo "SERVICES_DIR      : $SERVICES_DIR"
    echo "DEV_ENV           : $DEV_ENV"
    echo "LANDO_RECIPE      : $LANDO_RECIPE"
    echo "LANDO_PHP         : $LANDO_PHP"
    echo "LANDO_DB          : $LANDO_DB"
    echo "RUN_PREFIX        : $RUN_PREFIX"
    echo "CONSOLE_CMD       : $CONSOLE_CMD"
    echo "DB_HINTS          : $DB_HINTS"
    echo "USE_AI            : $USE_AI"
    echo "========================="
    exit 0
fi

# ── Metrics ───────────────────────────────────────────────────────────────────
info "Computing metrics..."

count_files() {
    find "$1" -name "*.$2" -not -path '*/vendor/*' -not -path '*/var/*' \
        -not -path '*/node_modules/*' -not -path '*/.git/*' 2>/dev/null | wc -l | xargs
}

TOTAL_PHP=0; TOTAL_TS=0; TOTAL_PY=0; TOTAL_GO=0; TOTAL_RS=0; TOTAL_RB=0
[ "$STACK_PHP"    = true ] && TOTAL_PHP=$(count_files . php)
[ "$STACK_NODE"   = true ] && TOTAL_TS=$(count_files . ts)
[ "$STACK_PYTHON" = true ] && TOTAL_PY=$(count_files . py)
[ "$STACK_GO"     = true ] && TOTAL_GO=$(count_files . go)
[ "$STACK_RUST"   = true ] && TOTAL_RS=$(count_files . rs)
[ "$STACK_RUBY"   = true ] && TOTAL_RB=$(count_files . rb)

ENTITY_COUNT=0; CONTROLLER_COUNT=0; SERVICE_COUNT=0; MIGRATION_COUNT=0
[ -n "$MODELS_DIR" ]     && [ -d "$MODELS_DIR" ]     && ENTITY_COUNT=$(find "$MODELS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs)
[ -n "$CONTROLLERS_DIR" ] && [ -d "$CONTROLLERS_DIR" ] && CONTROLLER_COUNT=$(find "$CONTROLLERS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs)
[ -n "$SERVICES_DIR" ]   && [ -d "$SERVICES_DIR" ]   && SERVICE_COUNT=$(find "$SERVICES_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs)
[ -d "${APP_DIR}/migrations" ] && MIGRATION_COUNT=$(find "${APP_DIR}/migrations" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs)

# ── Collect key files for AI context ─────────────────────────────────────────
info "Collecting source samples..."
AI_CONTEXT_FILES=""; CHARS_USED=0; CHAR_BUDGET=6000

add_file_to_context() {
    local file="$1"
    [ -f "$file" ] || return 0
    [ "$CHARS_USED" -ge "$CHAR_BUDGET" ] && return 0
    local content; content=$(head -c 800 "$file" 2>/dev/null)
    local len=${#content}
    AI_CONTEXT_FILES+="### $file\n\`\`\`\n${content}\n\`\`\`\n\n"
    CHARS_USED=$((CHARS_USED + len))
}

for f in composer.json package.json go.mod Cargo.toml Gemfile requirements.txt pyproject.toml; do
    add_file_to_context "${APP_DIR}/${f}"
done
add_file_to_context "${APP_DIR}/config/packages/security.yaml"
add_file_to_context "README.md"
[ -f "${APP_DIR}/.env" ] && {
    masked=$(grep -v '^#' "${APP_DIR}/.env" | grep -v '^$' | sed 's/=.*/=***/' 2>/dev/null)
    AI_CONTEXT_FILES+="### ${APP_DIR}/.env (masked)\n\`\`\`\n${masked}\n\`\`\`\n\n"
}
if [ -n "$MODELS_DIR" ] && [ -d "$MODELS_DIR" ]; then
    while IFS= read -r f; do add_file_to_context "$f"; done < <(find "$MODELS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | head -6)
fi
if [ -n "$CONTROLLERS_DIR" ] && [ -d "$CONTROLLERS_DIR" ]; then
    while IFS= read -r f; do add_file_to_context "$f"; done < <(find "$CONTROLLERS_DIR" -name "*.$PRIMARY_EXT" -type f -not -path "*/Admin/*" 2>/dev/null | head -4)
fi

# ── AI calls ──────────────────────────────────────────────────────────────────
GIT_LOG=$(git log --oneline -10 2>/dev/null || echo "No git history")
GIT_RECENT=$(git diff --name-only HEAD~5 HEAD 2>/dev/null | head -20 || echo "")

AI_OVERVIEW=""; AI_ARCHITECTURE=""; AI_FOCUS=""; AI_OPENAPI=""

# ── OpenAPI / Swagger spec detection ─────────────────────────────────────────
OPENAPI_FILE=""
for candidate in \
    openapi.yml openapi.yaml openapi.json \
    swagger.yml swagger.yaml swagger.json \
    api-docs.yml api-docs.yaml api-docs.json \
    api/openapi.yml api/openapi.yaml \
    docs/openapi.yml docs/openapi.yaml \
    public/api-docs.json public/openapi.json; do
    if [ -f "$candidate" ]; then
        OPENAPI_FILE="$candidate"
        break
    fi
done

# Fallback: search one level deep for any yaml/json file that looks like OpenAPI
if [ -z "$OPENAPI_FILE" ]; then
    OPENAPI_FILE=$(grep -rl "^openapi:\|\"openapi\":" --include="*.yml" --include="*.yaml" --include="*.json" . \
        --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git 2>/dev/null | head -1 || echo "")
fi

if [ "$USE_AI" = true ]; then
    info "Calling ${AI_CLI} — project overview..."
    AI_OVERVIEW=$(call_ai "You are generating documentation for a software project.

Project name: ${REPO_NAME}
Detected framework: ${PRIMARY_FRAMEWORK} (${PRIMARY_LANG})
Dev environment: ${DEV_ENV}
Database: ${DB_HINTS}
Recent commits:
${GIT_LOG}

Key project files (truncated):
$(echo -e "$AI_CONTEXT_FILES" | head -c 3000)

Write a concise 2-3 sentence project overview describing what it does, its purpose, and primary architectural approach. Output only the overview text — no preamble, no heading." 512)

    info "Calling ${AI_CLI} — architecture notes..."
    ENTITY_LIST=$([ -n "$MODELS_DIR" ] && [ -d "$MODELS_DIR" ] && \
        find "$MODELS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | xargs -I{} basename {} ".$PRIMARY_EXT" | sort | tr '\n' ', ' || echo "none")
    SERVICE_LIST=$([ -n "$SERVICES_DIR" ] && [ -d "$SERVICES_DIR" ] && \
        find "$SERVICES_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | xargs -I{} basename {} ".$PRIMARY_EXT" | sort | tr '\n' ', ' || echo "none")
    DIR_LIST=$(find "${SOURCE_DIR}" -type d -not -path '*/vendor/*' -not -path '*/var/*' \
        -not -path '*/node_modules/*' -not -path '*/.git/*' 2>/dev/null | sort | head -30)

    AI_ARCHITECTURE=$(call_ai "Analyse this ${PRIMARY_FRAMEWORK} (${PRIMARY_LANG}) codebase.

Entities/models: ${ENTITY_LIST}
Services: ${SERVICE_LIST}
Source directories:
${DIR_LIST}

Identify 3-5 key architectural patterns (e.g. repository pattern, service layer, DTO, CQRS). Base this only on the directory structure and class names provided. Return a markdown bullet list only — no preamble, no heading." 512)

    info "Calling ${AI_CLI} — development focus areas..."
    AI_FOCUS=$(call_ai "Analyse recent development activity on a ${PRIMARY_FRAMEWORK} project.

Recent commits:
${GIT_LOG}

Recently modified files:
${GIT_RECENT}

Based solely on the above, identify 3-5 areas of active development that would benefit from AI assistance. Return a markdown bullet list only — no preamble, no heading." 512)

    if [ -n "$OPENAPI_FILE" ]; then
        info "Calling ${AI_CLI} — analysing OpenAPI spec (${OPENAPI_FILE})..."
        OPENAPI_CONTENT=$(head -c 8000 "$OPENAPI_FILE")
        AI_OPENAPI=$(call_ai "You are documenting a REST API from its OpenAPI/Swagger specification.

Spec file: ${OPENAPI_FILE}
Contents (truncated to 8000 chars):
${OPENAPI_CONTENT}

Produce a concise API reference in this exact format:

### Overview
One paragraph summarising the API's purpose, version, and base URL if present.

### Authentication
How the API is secured (bearer token, API key, OAuth, etc.), or 'None specified' if absent.

### Endpoints
A markdown table with columns: Method | Path | Summary
List every endpoint found. Group by tag/resource if tags are present.

### Key Schemas
Bullet list of the most important request/response schemas with their key fields.

Output only the above sections — no preamble, no trailing commentary.")
    fi
fi

# ── Dependency block ──────────────────────────────────────────────────────────
DEPS_BLOCK=""
if [ "$HAS_JQ" = true ] && [ -f "${APP_DIR}/composer.json" ]; then
    DEPS_BLOCK=$(jq -r '
        (if .require then "**require:**\n" + (.require | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end),
        (if ."require-dev" then "\n**require-dev:**\n" + (."require-dev" | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end)
    ' "${APP_DIR}/composer.json" 2>/dev/null)
elif [ "$HAS_JQ" = true ] && [ -f "${APP_DIR}/package.json" ]; then
    DEPS_BLOCK=$(jq -r '
        (if .dependencies then "**dependencies:**\n" + (.dependencies | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end),
        (if .devDependencies then "\n**devDependencies:**\n" + (.devDependencies | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end)
    ' "${APP_DIR}/package.json" 2>/dev/null)
elif [ -f "${APP_DIR}/requirements.txt" ]; then DEPS_BLOCK=$(cat "${APP_DIR}/requirements.txt")
elif [ -f "${APP_DIR}/go.mod" ];           then DEPS_BLOCK=$(cat "${APP_DIR}/go.mod")
elif [ -f "${APP_DIR}/Gemfile" ];          then DEPS_BLOCK=$(cat "${APP_DIR}/Gemfile")
fi

# ── Route extraction ──────────────────────────────────────────────────────────
info "Extracting routes..."
ROUTES_BLOCK=""

if [ "$DEV_ENV" = "lando" ] && [ "$PRIMARY_FRAMEWORK" = "symfony" ]; then
    ROUTES_BLOCK=$(lando php bin/console debug:router 2>/dev/null | grep -v "^-\|^Name\|^\s*$" | head -60 || echo "Run: lando php bin/console debug:router")
elif [ "$PRIMARY_FRAMEWORK" = "laravel" ] && command -v php &>/dev/null; then
    ROUTES_BLOCK=$(php artisan route:list 2>/dev/null | head -60 || echo "Run: php artisan route:list")
elif [ "$PRIMARY_FRAMEWORK" = "express" ] || [ "$PRIMARY_FRAMEWORK" = "fastify" ]; then
    ROUTES_BLOCK=$(grep -rh "\.\(get\|post\|put\|delete\|patch\)\s*(" "${CONTROLLERS_DIR}" 2>/dev/null | grep -v "^\s*//" | head -40 || echo "")
elif [ "$PRIMARY_FRAMEWORK" = "fastapi" ] || [ "$PRIMARY_FRAMEWORK" = "flask" ]; then
    ROUTES_BLOCK=$(grep -rh "@\(app\|router\)\.\(get\|post\|put\|delete\|patch\)" . --include="*.py" 2>/dev/null | head -40 || echo "")
elif [ "$PRIMARY_FRAMEWORK" = "rails" ] && [ -f "config/routes.rb" ]; then
    ROUTES_BLOCK=$(cat config/routes.rb | head -60)
fi

# ── Scan functions ────────────────────────────────────────────────────────────
summarize_markdown_files() {
    [ "$USE_AI" = false ] && return
    
    info "Discovering and summarizing markdown files..."
    
    # Exclude common vendor/build directories
    local EXCLUDE_PATTERNS='node_modules|vendor|.git|dist|build|__pycache__'
    local md_files
    md_files=$(find . -name "*.md" -not -path "*/.*" | grep -vE "$EXCLUDE_PATTERNS" | sort)
    local count
    count=$(echo "$md_files" | grep -c ".md" || echo 0)
    
    info "Found $count markdown files."
    
    echo "$md_files" | while read -r f; do
        [ -z "$f" ] && continue
        local rel_path=${f#./}
        local content
        content=$(head -c 2000 "$f")
        
        local prompt
        if [[ "$rel_path" == docs/* ]]; then
            prompt="Summarize the following documentation file in detail (3-4 sentences), focusing on its core purpose and any architectural decisions it documents. File: $rel_path\n\nContent:\n$content"
        else
            prompt="Provide a brief 1-sentence summary of this markdown file. File: $rel_path\n\nContent:\n$content"
        fi
        
        local summary
        summary=$(call_ai "$prompt" < /dev/null)
        
        echo "### [$rel_path]($rel_path)"
        echo "> AI Summary: $summary"
        echo ""
    done
}

scan_models() {
    [ -z "$MODELS_DIR" ] || [ ! -d "$MODELS_DIR" ] && return
    find "$MODELS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | sort | while read -r f; do
        echo "#### $(basename "$f" ".$PRIMARY_EXT")"
        echo '```'"$PRIMARY_LANG"
        case "$PRIMARY_LANG" in
            php)    grep -E '^\s*(private|protected|public)\s+' "$f" 2>/dev/null | head -15 ;;
            python) grep -E '^\s*(class |    \w+ =|    \w+:)' "$f" 2>/dev/null | head -15 ;;
            go)     grep -E '^(type |func )' "$f" 2>/dev/null | head -15 ;;
            node)   grep -E '(export (default )?class|interface|readonly |private |public )' "$f" 2>/dev/null | head -15 ;;
            ruby)   grep -E '^\s*(belongs_to|has_many|has_one|validates|attr_)' "$f" 2>/dev/null | head -15 ;;
        esac
        echo '```'
        echo ""
    done
}

scan_controllers() {
    [ -z "$CONTROLLERS_DIR" ] || [ ! -d "$CONTROLLERS_DIR" ] && return
    find "$CONTROLLERS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | sort | while read -r f; do
        echo "#### $(basename "$f" ".$PRIMARY_EXT")"
        echo '```'"$PRIMARY_LANG"
        case "$PRIMARY_LANG" in
            php)    grep -E '^\s*#\[Route\(|^\s*public function' "$f" 2>/dev/null | head -20 ;;
            python) grep -E '@(app|router)\.(get|post|put|delete|patch)|^def |^async def ' "$f" 2>/dev/null | head -20 ;;
            go)     grep -E '^func ' "$f" 2>/dev/null | head -20 ;;
            node)   grep -E '\.(get|post|put|delete|patch)\s*\(|^export ' "$f" 2>/dev/null | head -20 ;;
            ruby)   grep -E '^\s*def ' "$f" 2>/dev/null | head -20 ;;
        esac
        echo '```'
        echo ""
    done
}

scan_services() {
    [ -z "$SERVICES_DIR" ] || [ ! -d "$SERVICES_DIR" ] && return
    find "$SERVICES_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | sort | while read -r f; do
        echo "#### $(basename "$f" ".$PRIMARY_EXT")"
        echo '```'"$PRIMARY_LANG"
        case "$PRIMARY_LANG" in
            php)    grep -E '^\s*public function' "$f" 2>/dev/null | head -12 ;;
            python) grep -E '^def |^async def |^    def ' "$f" 2>/dev/null | head -12 ;;
            go)     grep -E '^func ' "$f" 2>/dev/null | head -12 ;;
            node)   grep -E '^export (async )?function|^\s*async \w+\s*\(' "$f" 2>/dev/null | head -12 ;;
            ruby)   grep -E '^\s*def ' "$f" 2>/dev/null | head -12 ;;
        esac
        echo '```'
        echo ""
    done
}

# ── Helper blocks ─────────────────────────────────────────────────────────────
dev_setup_block() {
    case "$DEV_ENV" in
        lando)
            cat <<DEVEOF
\`\`\`bash
lando start
lando composer install
${CONSOLE_CMD} doctrine:migrations:migrate
${CONSOLE_CMD} cache:clear
\`\`\`
DEVEOF
            ;;
        docker)
            cat <<DEVEOF
\`\`\`bash
docker compose up -d
docker compose exec app composer install
\`\`\`
DEVEOF
            ;;
        make)
            printf '```bash\nmake dev\n```\n' ;;
        *)
            printf '```bash\n%s install\n```\n' "${RUN_PREFIX:-composer}" ;;
    esac
}

env_block() {
    if [ -f "${APP_DIR}/.env.example" ]; then
        grep -v '^#' "${APP_DIR}/.env.example" | grep -v '^$'
    elif [ -f "${APP_DIR}/.env" ]; then
        grep -v '^#' "${APP_DIR}/.env" | grep -v '^$' | sed 's/=.*/=***/'
    elif [ -f ".env.example" ]; then
        grep -v '^#' .env.example | grep -v '^$'
    elif [ -f ".env" ]; then
        grep -v '^#' .env | grep -v '^$' | sed 's/=.*/=***/'
    else
        echo "No .env or .env.example found."
    fi
}

migrations_block() {
    local mdir="${APP_DIR}/migrations"
    if [ -d "$mdir" ] && [ "$MIGRATION_COUNT" -gt 0 ]; then
        echo "| Migration | Date |"
        echo "|---|---|"
        find "$mdir" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | sort | tail -10 | while read -r f; do
            name=$(basename "$f" ".$PRIMARY_EXT")
            mdate=$(echo "$name" | grep -oE '[0-9]{8}' | head -1 || echo "—")
            echo "| \`$name\` | $mdate |"
        done
        [ "$MIGRATION_COUNT" -gt 10 ] && echo "_Showing latest 10 of ${MIGRATION_COUNT} total._"
    else
        echo "_No migrations directory found._"
    fi
}

# ── Stack label ───────────────────────────────────────────────────────────────
STACK_LABEL="${PRIMARY_FRAMEWORK}"
[ -n "$FRAMEWORK_VERSION" ] && STACK_LABEL+=" ${FRAMEWORK_VERSION}"
[ -n "$PHP_VERSION" ]       && STACK_LABEL+=" · PHP ${PHP_VERSION}"
[ -n "$NODE_VERSION" ]      && STACK_LABEL+=" · Node ${NODE_VERSION}"
[ -n "$LANDO_DB" ]          && STACK_LABEL+=" · ${LANDO_DB}"
[ -z "$LANDO_DB" ] && [ -n "$DB_HINTS" ] && STACK_LABEL+=" · ${DB_HINTS}"

# ── Write output ──────────────────────────────────────────────────────────────
info "Writing ${OUTPUT_FILE}..."
mkdir -p "$OUTPUT_DIR"

{

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Header — uses unquoted heredoc so variables expand
cat << EOF
# ${REPO_NAME} — Project Context

> Generated: $(date +"%Y-%m-%d %H:%M:%S") | Duration: ${DURATION}s | Stack: ${STACK_LABEL} | Dev: ${DEV_ENV}

---

## Overview

EOF

if [ -n "$AI_OVERVIEW" ]; then
    echo "$AI_OVERVIEW"
else
    echo "Auto-detected **${PRIMARY_FRAMEWORK}** (${PRIMARY_LANG}) project."
    [ -n "$DB_HINTS" ] && echo "Database: ${DB_HINTS}."
fi

if [ "$USE_AI" = true ]; then
cat << EOF

---

## Document Context

$(summarize_markdown_files)
EOF
fi

# All subsequent heredocs are single-quoted — no variable expansion needed
cat << 'SECTION'

---

## Metrics

SECTION

echo "| Category | Count |"
echo "|---|---|"
[ "$TOTAL_PHP" -gt 0 ] && echo "| PHP files         | $TOTAL_PHP |"
[ "$TOTAL_TS"  -gt 0 ] && echo "| TypeScript files  | $TOTAL_TS |"
[ "$TOTAL_PY"  -gt 0 ] && echo "| Python files      | $TOTAL_PY |"
[ "$TOTAL_GO"  -gt 0 ] && echo "| Go files          | $TOTAL_GO |"
[ "$TOTAL_RS"  -gt 0 ] && echo "| Rust files        | $TOTAL_RS |"
[ "$TOTAL_RB"  -gt 0 ] && echo "| Ruby files        | $TOTAL_RB |"
echo "| Entities/Models   | $ENTITY_COUNT |"
echo "| Controllers       | $CONTROLLER_COUNT |"
echo "| Services          | $SERVICE_COUNT |"
[ "$MIGRATION_COUNT" -gt 0 ] && echo "| Migrations        | $MIGRATION_COUNT |"

cat << 'SECTION'

---

## Technology Stack

SECTION

echo "| | |"
echo "|---|---|"
echo "| **Language**      | ${PRIMARY_LANG} |"
echo "| **Framework**     | ${PRIMARY_FRAMEWORK}${FRAMEWORK_VERSION:+ ${FRAMEWORK_VERSION}} |"
[ -n "$PHP_VERSION" ]  && echo "| **PHP**           | ${PHP_VERSION} |"
[ -n "$NODE_VERSION" ] && echo "| **Node**          | ${NODE_VERSION} |"
[ -n "$LANDO_DB" ]     && echo "| **Database**      | ${LANDO_DB} |"
[ -z "$LANDO_DB" ] && [ -n "$DB_HINTS" ] && echo "| **Database**      | ${DB_HINTS} |"
echo "| **Dev env**       | ${DEV_ENV}${LANDO_RECIPE:+ (${LANDO_RECIPE})} |"

echo ""
echo "### Dependencies"
echo ""
echo "$DEPS_BLOCK"

cat << 'SECTION'

---

## Project Structure

```
SECTION

if command -v tree &>/dev/null; then
    tree -L "$TREE_DEPTH" -I 'vendor|var|node_modules|.git|__pycache__|target|dist|build' --dirsfirst 2>/dev/null
else
    find . -type d \
        -not -path '*/vendor/*' -not -path '*/var/*' -not -path '*/node_modules/*' \
        -not -path '*/.git/*' -not -path '*/__pycache__/*' | sort | head -40
fi

echo '```'

cat << 'SECTION'

---

## Data Models

SECTION

scan_models

cat << 'SECTION'

---

## API Routes

```
SECTION

if [ -n "$ROUTES_BLOCK" ]; then echo "$ROUTES_BLOCK"; else echo "Run: ${CONSOLE_CMD} debug:router"; fi

echo '```'

cat << 'SECTION'

---

## Controllers

SECTION

scan_controllers

cat << 'SECTION'

---

## Services

SECTION

scan_services

cat << 'SECTION'

---

## Migrations

SECTION

migrations_block

cat << 'SECTION'

---

## Environment Variables

```bash
SECTION

env_block
echo '```'

cat << 'SECTION'

---

## Development Setup

SECTION

dev_setup_block

cat << 'SECTION'

---

## Recent Git Activity

```
SECTION

git log --oneline -15 2>/dev/null || echo "Git history not available"
echo '```'

cat << 'SECTION'

---

## Architecture Notes

SECTION

if [ -n "$AI_ARCHITECTURE" ]; then
    echo "$AI_ARCHITECTURE"
else
    echo "Detected source layers:"
    for dir in "$MODELS_DIR" "$CONTROLLERS_DIR" "$SERVICES_DIR"; do
        [ -n "$dir" ] && [ -d "$dir" ] && \
            echo "- \`${dir}/\` — $(find "$dir" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs) files"
    done
fi

cat << 'SECTION'

---

## Current Development Focus

SECTION

if [ -n "$AI_FOCUS" ]; then
    echo "$AI_FOCUS"
else
    echo '```'
    echo "$GIT_LOG"
    echo '```'
fi

if [ -n "$OPENAPI_FILE" ]; then
cat << 'SECTION'

---

## API Specification

SECTION

echo "> Source: \`${OPENAPI_FILE}\`"
echo ""

if [ -n "$AI_OPENAPI" ]; then
    echo "$AI_OPENAPI"
else
    echo '```'
    head -c 4000 "$OPENAPI_FILE"
    echo '```'
fi
fi

if [ "$USE_AI" = true ]; then
cat << EOF

---

> _AI summaries generated using **${AI_CLI}**._
EOF
fi

} > "$TEMP_FILE"

mv "$TEMP_FILE" "$OUTPUT_FILE"

echo ""
success "${OUTPUT_FILE} generated"
echo ""
echo "  Stack   : ${STACK_LABEL}"
echo "  Dev env : ${DEV_ENV}"
echo "  AI      : ${USE_AI}"
echo "  Output  : ${OUTPUT_FILE}"
echo ""
