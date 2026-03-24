#!/bin/bash
#
# generate_project_context.sh
# Drop into any project root — auto-detects stack, uses Claude to generate context.
#
# Usage:
#   bash scripts/generate_project_context.sh [--no-ai] [--output-dir <dir>] [--depth <n>] [--debug-detection]
#
# Requirements: jq
# Optional:     Claude Code CLI (claude) — enables AI-generated summaries

set -e

# ── Argument parsing ──────────────────────────────────────────────────────────
USE_AI=true
OUTPUT_DIR="docs"
TREE_DEPTH=3
DEBUG_DETECTION=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-ai)            USE_AI=false ;;
        --output-dir)       OUTPUT_DIR="$2"; shift ;;
        --depth)            TREE_DEPTH="$2"; shift ;;
        --debug-detection)  DEBUG_DETECTION=true ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

# ── Config ────────────────────────────────────────────────────────────────────
REPO_NAME=$(basename "$PWD")
OUTPUT_FILE="${OUTPUT_DIR}/${REPO_NAME}-context.md"
TEMP_FILE="${OUTPUT_FILE}.tmp"

# ── Colors ────────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()    { echo -e "${BLUE}▸ $1${NC}"; }
success() { echo -e "${GREEN}✓ $1${NC}"; }
warn()    { echo -e "${YELLOW}⚠ $1${NC}" >&2; }

# ── Dependency checks ─────────────────────────────────────────────────────────
if ! command -v jq &>/dev/null; then
    warn "jq not found — JSON parsing will be limited. Install: brew install jq"
    HAS_JQ=false
else
    HAS_JQ=true
fi

# ── Claude Code CLI check ─────────────────────────────────────────────────────
if [ "$USE_AI" = true ]; then
    if [ -n "$CLAUDECODE" ]; then
        info "Running inside a Claude Code session — AI summaries skipped (nested sessions not supported)."
        USE_AI=false
    elif command -v claude &>/dev/null; then
        info "Claude Code CLI detected — AI summaries enabled."
    else
        info "Claude Code CLI not found — AI summaries skipped. Install claude to enable."
        USE_AI=false
    fi
fi

# ── Claude Code CLI helper ────────────────────────────────────────────────────
# Uses `claude -p` (print mode) — non-interactive, exits after one response.
call_claude() {
    local prompt="$1"
    [ "$USE_AI" = false ] && echo "" && return 0
    local result
    result=$(claude -p "$prompt" 2>/dev/null)
    [ -z "$result" ] && warn "Claude returned empty for: ${prompt:0:60}..."
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

if [ -f "composer.json" ]; then
    STACK_PHP=true; PRIMARY_LANG="php"; PRIMARY_EXT="php"; SOURCE_DIR="src"; SERVICES_DIR="src/Service"
    if [ "$HAS_JQ" = true ]; then
        if jq -e '.require | has("symfony/framework-bundle")' composer.json &>/dev/null; then
            STACK_SYMFONY=true; PRIMARY_FRAMEWORK="symfony"
            MODELS_DIR="src/Entity"; CONTROLLERS_DIR="src/Controller"
        elif jq -e '.require | has("laravel/framework")' composer.json &>/dev/null; then
            STACK_LARAVEL=true; PRIMARY_FRAMEWORK="laravel"
            MODELS_DIR="app/Models"; CONTROLLERS_DIR="app/Http/Controllers"; SERVICES_DIR="app/Services"
        else
            PRIMARY_FRAMEWORK="php"; MODELS_DIR="src"; CONTROLLERS_DIR="src"
        fi
    fi
fi

if [ -f "package.json" ]; then
    STACK_NODE=true
    [ "$PRIMARY_LANG" = "unknown" ] && PRIMARY_LANG="node"
    PRIMARY_EXT="ts"
    [ ! -d "src" ] && SOURCE_DIR="app"
    if [ "$HAS_JQ" = true ]; then
        if jq -e '.dependencies | has("next")' package.json &>/dev/null; then
            STACK_NEXT=true; PRIMARY_FRAMEWORK="nextjs"
            MODELS_DIR="app/models"; CONTROLLERS_DIR="app/api"; SERVICES_DIR="app/services"
        elif jq -e '.dependencies | has("express")' package.json &>/dev/null; then
            STACK_EXPRESS=true; PRIMARY_FRAMEWORK="express"
            MODELS_DIR="src/models"; CONTROLLERS_DIR="src/controllers"; SERVICES_DIR="src/services"
        fi
    fi
fi

for pyfile in requirements.txt pyproject.toml setup.py; do
    if [ -f "$pyfile" ]; then
        STACK_PYTHON=true
        [ "$PRIMARY_LANG" = "unknown" ] && PRIMARY_LANG="python"
        PRIMARY_EXT="py"; SOURCE_DIR="."
        if grep -qi "django" "$pyfile" 2>/dev/null; then
            STACK_DJANGO=true; PRIMARY_FRAMEWORK="django"
            MODELS_DIR="*/models.py"; CONTROLLERS_DIR="*/views.py"; SERVICES_DIR="*/services"
        elif grep -qi "fastapi" "$pyfile" 2>/dev/null; then
            STACK_FASTAPI=true; PRIMARY_FRAMEWORK="fastapi"
            MODELS_DIR="app/models"; CONTROLLERS_DIR="app/routers"; SERVICES_DIR="app/services"
        elif grep -qi "flask" "$pyfile" 2>/dev/null; then
            STACK_FLASK=true; PRIMARY_FRAMEWORK="flask"
            MODELS_DIR="app/models"; CONTROLLERS_DIR="app/routes"; SERVICES_DIR="app/services"
        fi
        break
    fi
done

if [ -f "go.mod" ]; then
    STACK_GO=true; PRIMARY_LANG="go"; PRIMARY_FRAMEWORK="go"; PRIMARY_EXT="go"
    SOURCE_DIR="."; MODELS_DIR="internal/model"; CONTROLLERS_DIR="internal/handler"; SERVICES_DIR="internal/service"
fi

if [ -f "Cargo.toml" ]; then
    STACK_RUST=true; PRIMARY_LANG="rust"; PRIMARY_FRAMEWORK="rust"; PRIMARY_EXT="rs"
    SOURCE_DIR="src"; MODELS_DIR="src/models"; CONTROLLERS_DIR="src/handlers"; SERVICES_DIR="src/services"
fi

if [ -f "Gemfile" ]; then
    STACK_RUBY=true; PRIMARY_LANG="ruby"; PRIMARY_EXT="rb"
    if grep -qi "rails" Gemfile 2>/dev/null; then
        STACK_RAILS=true; PRIMARY_FRAMEWORK="rails"
        MODELS_DIR="app/models"; CONTROLLERS_DIR="app/controllers"; SERVICES_DIR="app/services"
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
    [ -f "$file" ] || continue
    grep -qi "mysql"    "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}MySQL "
    grep -qi "postgres" "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}PostgreSQL "
    grep -qi "mongodb"  "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}MongoDB "
    grep -qi "sqlite"   "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}SQLite "
    grep -qi "redis"    "$file" 2>/dev/null && DB_HINTS="${DB_HINTS}Redis "
done
DB_HINTS=$(echo "$DB_HINTS" | tr ' ' '\n' | sort -u | grep -v '^$' | tr '\n' ' ' | xargs)

# ── Version extraction ────────────────────────────────────────────────────────
FRAMEWORK_VERSION=""; PHP_VERSION=""; NODE_VERSION=""
if [ "$HAS_JQ" = true ]; then
    if [ -f "composer.json" ]; then
        PHP_VERSION=$(jq -r '.require.php // ""' composer.json 2>/dev/null | tr -d '>=^~< ')
        [ "$PRIMARY_FRAMEWORK" = "symfony" ] && \
            FRAMEWORK_VERSION=$(jq -r '.require["symfony/framework-bundle"] // ""' composer.json 2>/dev/null | tr -d '>=^~<*. ')
        [ "$PRIMARY_FRAMEWORK" = "laravel" ] && \
            FRAMEWORK_VERSION=$(jq -r '.require["laravel/framework"] // ""' composer.json 2>/dev/null | tr -d '>=^~< ')
    fi
    if [ -f "package.json" ]; then
        NODE_VERSION=$(jq -r '.engines.node // ""' package.json 2>/dev/null | tr -d '>=^~< ')
        [ "$PRIMARY_FRAMEWORK" = "nextjs" ] && \
            FRAMEWORK_VERSION=$(jq -r '.dependencies.next // ""' package.json 2>/dev/null | tr -d '>=^~< ')
    fi
fi

# ── Debug detection dump ──────────────────────────────────────────────────────
if [ "$DEBUG_DETECTION" = true ]; then
    echo ""
    echo "=== Detection Results ==="
    echo "REPO_NAME         : $REPO_NAME"
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
[ -d "migrations" ] && MIGRATION_COUNT=$(find migrations -name "*.$PRIMARY_EXT" -type f 2>/dev/null | wc -l | xargs)

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
    add_file_to_context "$f"
done
add_file_to_context "config/packages/security.yaml"
add_file_to_context "README.md"
[ -f ".env" ] && {
    masked=$(grep -v '^#' .env | grep -v '^$' | sed 's/=.*/=***/' 2>/dev/null)
    AI_CONTEXT_FILES+="### .env (masked)\n\`\`\`\n${masked}\n\`\`\`\n\n"
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
    info "Calling Claude — project overview..."
    AI_OVERVIEW=$(call_claude "You are generating documentation for a software project.

Project name: ${REPO_NAME}
Detected framework: ${PRIMARY_FRAMEWORK} (${PRIMARY_LANG})
Dev environment: ${DEV_ENV}
Database: ${DB_HINTS}
Recent commits:
${GIT_LOG}

Key project files (truncated):
$(echo -e "$AI_CONTEXT_FILES" | head -c 3000)

Write a concise 2-3 sentence project overview describing what it does, its purpose, and primary architectural approach. Output only the overview text — no preamble, no heading." 512)

    info "Calling Claude — architecture notes..."
    ENTITY_LIST=$([ -n "$MODELS_DIR" ] && [ -d "$MODELS_DIR" ] && \
        find "$MODELS_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | xargs -I{} basename {} ".$PRIMARY_EXT" | sort | tr '\n' ', ' || echo "none")
    SERVICE_LIST=$([ -n "$SERVICES_DIR" ] && [ -d "$SERVICES_DIR" ] && \
        find "$SERVICES_DIR" -name "*.$PRIMARY_EXT" -type f 2>/dev/null | xargs -I{} basename {} ".$PRIMARY_EXT" | sort | tr '\n' ', ' || echo "none")
    DIR_LIST=$(find "${SOURCE_DIR}" -type d -not -path '*/vendor/*' -not -path '*/var/*' \
        -not -path '*/node_modules/*' -not -path '*/.git/*' 2>/dev/null | sort | head -30)

    AI_ARCHITECTURE=$(call_claude "Analyse this ${PRIMARY_FRAMEWORK} (${PRIMARY_LANG}) codebase.

Entities/models: ${ENTITY_LIST}
Services: ${SERVICE_LIST}
Source directories:
${DIR_LIST}

Identify 3-5 key architectural patterns (e.g. repository pattern, service layer, DTO, CQRS). Base this only on the directory structure and class names provided. Return a markdown bullet list only — no preamble, no heading." 512)

    info "Calling Claude — development focus areas..."
    AI_FOCUS=$(call_claude "Analyse recent development activity on a ${PRIMARY_FRAMEWORK} project.

Recent commits:
${GIT_LOG}

Recently modified files:
${GIT_RECENT}

Based solely on the above, identify 3-5 areas of active development that would benefit from AI assistance. Return a markdown bullet list only — no preamble, no heading." 512)

    if [ -n "$OPENAPI_FILE" ]; then
        info "Calling Claude — analysing OpenAPI spec (${OPENAPI_FILE})..."
        OPENAPI_CONTENT=$(head -c 8000 "$OPENAPI_FILE")
        AI_OPENAPI=$(call_claude "You are documenting a REST API from its OpenAPI/Swagger specification.

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
if [ "$HAS_JQ" = true ] && [ -f "composer.json" ]; then
    DEPS_BLOCK=$(jq -r '
        (if .require then "**require:**\n" + (.require | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end),
        (if ."require-dev" then "\n**require-dev:**\n" + (."require-dev" | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end)
    ' composer.json 2>/dev/null)
elif [ "$HAS_JQ" = true ] && [ -f "package.json" ]; then
    DEPS_BLOCK=$(jq -r '
        (if .dependencies then "**dependencies:**\n" + (.dependencies | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end),
        (if .devDependencies then "\n**devDependencies:**\n" + (.devDependencies | to_entries | map("- `\(.key)`: \(.value)") | join("\n")) else "" end)
    ' package.json 2>/dev/null)
elif [ -f "requirements.txt" ]; then DEPS_BLOCK=$(cat requirements.txt)
elif [ -f "go.mod" ];           then DEPS_BLOCK=$(cat go.mod)
elif [ -f "Gemfile" ];          then DEPS_BLOCK=$(cat Gemfile)
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
    if [ -f ".env.example" ]; then
        grep -v '^#' .env.example | grep -v '^$'
    elif [ -f ".env" ]; then
        grep -v '^#' .env | grep -v '^$' | sed 's/=.*/=***/'
    else
        echo "No .env or .env.example found."
    fi
}

migrations_block() {
    if [ -d "migrations" ] && [ "$MIGRATION_COUNT" -gt 0 ]; then
        echo "| Migration | Date |"
        echo "|---|---|"
        find migrations -name "*.$PRIMARY_EXT" -type f 2>/dev/null | sort | tail -10 | while read -r f; do
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

# Header — uses unquoted heredoc so variables expand
cat << EOF
# ${REPO_NAME} — Project Context

> Generated: $(date +"%Y-%m-%d %H:%M:%S") | Stack: ${STACK_LABEL} | Dev: ${DEV_ENV}

---

## Overview

EOF

if [ -n "$AI_OVERVIEW" ]; then
    echo "$AI_OVERVIEW"
else
    echo "Auto-detected **${PRIMARY_FRAMEWORK}** (${PRIMARY_LANG}) project."
    [ -n "$DB_HINTS" ] && echo "Database: ${DB_HINTS}."
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
