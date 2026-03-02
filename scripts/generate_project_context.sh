#!/bin/bash

# generate_project_context.sh
# Generates a comprehensive PROJECT_CONTEXT.md file for Claude.ai integration

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}Generating PROJECT_CONTEXT.md...${NC}"

# Ensure docs directory exists
mkdir -p docs

# Start building the context file
cat > docs/PROJECT_CONTEXT.md << 'EOF'
# Wunderkind Backend - Project Context

> Last Updated: $(date +"%Y-%m-%d %H:%M:%S")

## Overview
Wunderkind Factory backend API built with Symfony for managing youth football academies and leaderboard systems.

---

## Technology Stack

### Core Framework
- **Symfony**: 6.4
- **PHP**: 8.x
- **Database**: MySQL/MariaDB
- **Local Dev**: Lando

### Key Packages
EOF

# Add composer packages
echo "- Extracting Composer dependencies..."
cat >> docs/PROJECT_CONTEXT.md << 'EOF'

```json
EOF

if [ -f "composer.json" ]; then
    cat composer.json | jq -r '.require' >> docs/PROJECT_CONTEXT.md 2>/dev/null || \
    grep -A 50 '"require"' composer.json | grep -B 50 '}' | head -n -1 >> docs/PROJECT_CONTEXT.md
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'
```

---

## Project Structure

```
EOF

# Add directory tree (limited depth)
echo "- Building directory structure..."
if command -v tree &> /dev/null; then
    tree -L 3 -I 'vendor|var|node_modules' --dirsfirst >> docs/PROJECT_CONTEXT.md
else
    echo "Note: 'tree' command not found. Install with: brew install tree (macOS) or apt-get install tree (Linux)" >> docs/PROJECT_CONTEXT.md
    find . -type d -not -path '*/vendor/*' -not -path '*/var/*' -not -path '*/node_modules/*' -not -path '*/.git/*' | head -30 >> docs/PROJECT_CONTEXT.md
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'
```

---

## Database Entities

EOF

# List all entities
echo "- Scanning entities..."
if [ -d "src/Entity" ]; then
    echo "### Available Entities" >> docs/PROJECT_CONTEXT.md
    echo "" >> docs/PROJECT_CONTEXT.md
    for entity in src/Entity/*.php; do
        if [ -f "$entity" ]; then
            entity_name=$(basename "$entity" .php)
            echo "#### $entity_name" >> docs/PROJECT_CONTEXT.md
            echo '```php' >> docs/PROJECT_CONTEXT.md
            # Extract class definition and properties
            sed -n '/^class /,/^{/p' "$entity" >> docs/PROJECT_CONTEXT.md
            grep -E '^\s*(private|protected|public)\s+' "$entity" | head -20 >> docs/PROJECT_CONTEXT.md
            echo '```' >> docs/PROJECT_CONTEXT.md
            echo "" >> docs/PROJECT_CONTEXT.md
        fi
    done
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## API Routes

EOF

# Get API routes
echo "- Extracting API routes..."
if command -v lando &> /dev/null; then
    echo '```' >> docs/PROJECT_CONTEXT.md
    lando php bin/console debug:router 2>/dev/null | grep -E '^[a-z_]' | head -50 >> docs/PROJECT_CONTEXT.md || echo "Run 'lando php bin/console debug:router' to see routes" >> docs/PROJECT_CONTEXT.md
    echo '```' >> docs/PROJECT_CONTEXT.md
else
    echo '```' >> docs/PROJECT_CONTEXT.md
    echo "Lando not available. Routes can be found via:" >> docs/PROJECT_CONTEXT.md
    echo "php bin/console debug:router" >> docs/PROJECT_CONTEXT.md
    echo '```' >> docs/PROJECT_CONTEXT.md
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Controllers

EOF

# List controllers with their methods
echo "- Scanning controllers..."
if [ -d "src/Controller" ]; then
    for controller in src/Controller/*.php; do
        if [ -f "$controller" ]; then
            controller_name=$(basename "$controller" .php)
            echo "### $controller_name" >> docs/PROJECT_CONTEXT.md
            echo "" >> docs/PROJECT_CONTEXT.md
            echo '```php' >> docs/PROJECT_CONTEXT.md
            # Extract public methods (route handlers)
            grep -E '^\s*#\[Route\(|^\s*public function' "$controller" | head -20 >> docs/PROJECT_CONTEXT.md
            echo '```' >> docs/PROJECT_CONTEXT.md
            echo "" >> docs/PROJECT_CONTEXT.md
        fi
    done
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Services

EOF

# List services
echo "- Scanning services..."
if [ -d "src/Service" ]; then
    for service in src/Service/*.php; do
        if [ -f "$service" ]; then
            service_name=$(basename "$service" .php)
            echo "### $service_name" >> docs/PROJECT_CONTEXT.md
            echo "" >> docs/PROJECT_CONTEXT.md
            echo '```php' >> docs/PROJECT_CONTEXT.md
            # Extract class definition and public methods
            sed -n '/^class /,/^{/p' "$service" >> docs/PROJECT_CONTEXT.md
            grep -E '^\s*public function' "$service" | head -10 >> docs/PROJECT_CONTEXT.md
            echo '```' >> docs/PROJECT_CONTEXT.md
            echo "" >> docs/PROJECT_CONTEXT.md
        fi
    done
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Security Configuration

EOF

# Add security.yaml
echo "- Extracting security configuration..."
if [ -f "config/packages/security.yaml" ]; then
    echo '```yaml' >> docs/PROJECT_CONTEXT.md
    cat config/packages/security.yaml >> docs/PROJECT_CONTEXT.md
    echo '```' >> docs/PROJECT_CONTEXT.md
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Environment Configuration

### Required Environment Variables

EOF

# Extract .env.example or .env variables
if [ -f ".env.example" ]; then
    echo '```bash' >> docs/PROJECT_CONTEXT.md
    grep -v '^#' .env.example | grep -v '^$' >> docs/PROJECT_CONTEXT.md
    echo '```' >> docs/PROJECT_CONTEXT.md
elif [ -f ".env" ]; then
    echo '```bash' >> docs/PROJECT_CONTEXT.md
    grep -v '^#' .env | grep -v '^$' | sed 's/=.*/=***/' >> docs/PROJECT_CONTEXT.md
    echo '```' >> docs/PROJECT_CONTEXT.md
fi

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Development Setup

### Local Development with Lando

```bash
# Start the environment
lando start

# Install dependencies
lando composer install

# Database setup
lando php bin/console doctrine:database:create
lando php bin/console doctrine:migrations:migrate

# Clear cache
lando php bin/console cache:clear
```

### Useful Commands

```bash
# View logs
lando logs -s appserver

# Run tests
lando php bin/phpunit

# Debug routes
lando php bin/console debug:router

# Debug firewall
lando php bin/console debug:firewall
```

---

## Recent Development Activity

EOF

# Add recent git commits
echo "- Extracting recent commits..."
echo '```' >> docs/PROJECT_CONTEXT.md
git log --oneline -10 2>/dev/null >> docs/PROJECT_CONTEXT.md || echo "Git history not available" >> docs/PROJECT_CONTEXT.md
echo '```' >> docs/PROJECT_CONTEXT.md

cat >> docs/PROJECT_CONTEXT.md << 'EOF'

---

## Notes for AI Context

### Current Focus Areas
- JWT Authentication implementation
- Leaderboard sync endpoints
- Admin UI development
- Academy management system

### Key Design Patterns
- Repository pattern for data access
- Service layer for business logic
- DTO pattern for API requests/responses
- Event-driven architecture where applicable

### Testing Strategy
- Unit tests for services
- Integration tests for repositories
- API tests for controllers

---

## Additional Resources

- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Doctrine ORM](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/)
- [JWT Authentication Bundle](https://github.com/lexik/LexikJWTAuthenticationBundle)

EOF

echo -e "${GREEN}✓ PROJECT_CONTEXT.md generated successfully at docs/PROJECT_CONTEXT.md${NC}"
echo ""
echo "Next steps:"
echo "1. Review docs/PROJECT_CONTEXT.md"
echo "2. Upload to Claude.ai project knowledge base"
echo "3. Re-run this script whenever major changes occur"
