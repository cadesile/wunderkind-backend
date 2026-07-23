# scripts/global-context-generator/README.md

> Title: generate_project_context · 674 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Supported Stacks
- Installation
-   Option 1 — project-local
-   Option 2 — global (run from any project)
- Requirements
- Usage
-   Options
-   Examples
- Output
- How it works

## Summary
This README documents `generate_project_context.sh`, a bash script that auto-detects a project's tech stack (React Native/Expo, Symfony, Laravel, Django, Rails, or Go) and generates a single consolidated markdown context file covering schema, entities, routes, services, store shapes, dependencies, and dev setup — optionally enriched with AI-generated architecture notes via Claude or Gemini CLI. It covers installation (project-local or global), CLI flags (`--no-ai`, `--ai`, `--output-dir`, `--depth`, `--debug-detection`), requirements (bash, jq, git, optional tree/AI CLI), and the exact sections the output file contains. An agent should read this when asked to generate, configure, or troubleshoot project-context output for a supported stack, or to understand what data the script extracts and how (static grep/awk extraction plus optional AI pass).
