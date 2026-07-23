# Stage 01_overview

## Inputs
- source: composer.json / package.json / go.mod / Gemfile / requirements.txt (stack + versions)
- source: .lando.yml / docker-compose.yml / .devcontainer / Makefile (dev env)
- source: .env / .env.example (masked)

## Process
Detected the tech stack (symfony 8.0 · PHP 8.4 · postgres:16), dev environment (lando), databases (PostgreSQL), and computed file metrics. All scans respect the ignore rules in _config/ignore.

## Outputs
- output/stack.md — stack, versions, dependencies
- output/environment.md — dev env, masked env vars, setup commands
- output/metrics.md — file and component counts
