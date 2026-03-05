#!/bin/bash

# generate_project_context.sh
# Generates a comprehensive ${OUTPUT_FILE} file for Claude.ai integration

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the repository name from the current directory
REPO_NAME=$(basename "$PWD")
# Define the output path using the repo name
OUTPUT_FILE="${REPO_NAME}-context.md"

echo -e "${BLUE}Generating ${OUTPUT_FILE}...${NC}"

# Ensure docs directory exists
mkdir -p docs

# Start building the context file
cat > docs/${OUTPUT_FILE} << 'EOF'
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
cat >> docs/${OUTPUT_FILE} << 'EOF'

```json
EOF

if [ -f "composer.json" ]; then
    cat composer.json | jq -r '.require' >> docs/${OUTPUT_FILE} 2>/dev/null || \
    grep -A 50 '"require"' composer.json | grep -B 50 '}' | head -n -1 >> docs/${OUTPUT_FILE}
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'
```

---

## Project Structure

```
EOF

# Add directory tree (limited depth)
echo "- Building directory structure..."
if command -v tree &> /dev/null; then
    tree -L 3 -I 'vendor|var|node_modules' --dirsfirst >> docs/${OUTPUT_FILE}
else
    echo "Note: 'tree' command not found. Install with: brew install tree (macOS) or apt-get install tree (Linux)" >> docs/${OUTPUT_FILE}
    find . -type d -not -path '*/vendor/*' -not -path '*/var/*' -not -path '*/node_modules/*' -not -path '*/.git/*' | head -30 >> docs/${OUTPUT_FILE}
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'
```

---

## Database Entities

EOF

# List all entities
echo "- Scanning entities..."
if [ -d "src/Entity" ]; then
    echo "### Available Entities" >> docs/${OUTPUT_FILE}
    echo "" >> docs/${OUTPUT_FILE}
    for entity in src/Entity/*.php; do
        if [ -f "$entity" ]; then
            entity_name=$(basename "$entity" .php)
            echo "#### $entity_name" >> docs/${OUTPUT_FILE}
            echo '```php' >> docs/${OUTPUT_FILE}
            # Extract class definition and properties
            sed -n '/^class /,/^{/p' "$entity" >> docs/${OUTPUT_FILE}
            grep -E '^\s*(private|protected|public)\s+' "$entity" | head -20 >> docs/${OUTPUT_FILE}
            echo '```' >> docs/${OUTPUT_FILE}
            echo "" >> docs/${OUTPUT_FILE}
        fi
    done
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

---

## API Routes

EOF

# Get API routes
echo "- Extracting API routes..."
if command -v lando &> /dev/null; then
    echo '```' >> docs/${OUTPUT_FILE}
    lando php bin/console debug:router 2>/dev/null | grep -E '^[a-z_]' | head -50 >> docs/${OUTPUT_FILE} || echo "Run 'lando php bin/console debug:router' to see routes" >> docs/${OUTPUT_FILE}
    echo '```' >> docs/${OUTPUT_FILE}
else
    echo '```' >> docs/${OUTPUT_FILE}
    echo "Lando not available. Routes can be found via:" >> docs/${OUTPUT_FILE}
    echo "php bin/console debug:router" >> docs/${OUTPUT_FILE}
    echo '```' >> docs/${OUTPUT_FILE}
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

---

## Controllers

EOF

# List controllers with their methods
echo "- Scanning controllers..."
if [ -d "src/Controller" ]; then
    for controller in src/Controller/*.php; do
        if [ -f "$controller" ]; then
            controller_name=$(basename "$controller" .php)
            echo "### $controller_name" >> docs/${OUTPUT_FILE}
            echo "" >> docs/${OUTPUT_FILE}
            echo '```php' >> docs/${OUTPUT_FILE}
            # Extract public methods (route handlers)
            grep -E '^\s*#\[Route\(|^\s*public function' "$controller" | head -20 >> docs/${OUTPUT_FILE}
            echo '```' >> docs/${OUTPUT_FILE}
            echo "" >> docs/${OUTPUT_FILE}
        fi
    done
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

---

## Services

EOF

# List services
echo "- Scanning services..."
if [ -d "src/Service" ]; then
    for service in src/Service/*.php; do
        if [ -f "$service" ]; then
            service_name=$(basename "$service" .php)
            echo "### $service_name" >> docs/${OUTPUT_FILE}
            echo "" >> docs/${OUTPUT_FILE}
            echo '```php' >> docs/${OUTPUT_FILE}
            # Extract class definition and public methods
            sed -n '/^class /,/^{/p' "$service" >> docs/${OUTPUT_FILE}
            grep -E '^\s*public function' "$service" | head -10 >> docs/${OUTPUT_FILE}
            echo '```' >> docs/${OUTPUT_FILE}
            echo "" >> docs/${OUTPUT_FILE}
        fi
    done
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

---

## Security Configuration

EOF

# Add security.yaml
echo "- Extracting security configuration..."
if [ -f "config/packages/security.yaml" ]; then
    echo '```yaml' >> docs/${OUTPUT_FILE}
    cat config/packages/security.yaml >> docs/${OUTPUT_FILE}
    echo '```' >> docs/${OUTPUT_FILE}
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

---

## Environment Configuration

### Required Environment Variables

EOF

# Extract .env.example or .env variables
if [ -f ".env.example" ]; then
    echo '```bash' >> docs/${OUTPUT_FILE}
    grep -v '^#' .env.example | grep -v '^$' >> docs/${OUTPUT_FILE}
    echo '```' >> docs/${OUTPUT_FILE}
elif [ -f ".env" ]; then
    echo '```bash' >> docs/${OUTPUT_FILE}
    grep -v '^#' .env | grep -v '^$' | sed 's/=.*/=***/' >> docs/${OUTPUT_FILE}
    echo '```' >> docs/${OUTPUT_FILE}
fi

cat >> docs/${OUTPUT_FILE} << 'EOF'

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
echo '```' >> docs/${OUTPUT_FILE}
git log --oneline -10 2>/dev/null >> docs/${OUTPUT_FILE} || echo "Git history not available" >> docs/${OUTPUT_FILE}
echo '```' >> docs/${OUTPUT_FILE}

cat >> docs/${OUTPUT_FILE} << 'EOF'

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

echo -e "${GREEN}✓ ${OUTPUT_FILE} generated successfully at docs/${OUTPUT_FILE}${NC}"
echo ""
echo "Next steps:"
echo "1. Review docs/${OUTPUT_FILE}"
echo "2. Upload to Claude.ai project knowledge base"
echo "3. Re-run this script whenever major changes occur"
