# icm-codebase-context — Task Router

> This is the skill's own router ("what should I do") — not
> `.context/CONTEXT.md` in the target repo, which is a different, generated
> file that routes "what do I already know" over the stage outputs. Don't
> confuse the two even though both are Layer-1 routers in ICM terms.

| If the task is... | Go to |
|---|---|
| First run — `.context/` doesn't exist yet or every stage's `output/` is empty | `setup/questionnaire.md`, then `stages/01_overview/CONTEXT.md` |
| Understand stack, language, dev environment | `stages/01_overview/CONTEXT.md` |
| Understand directory layout / module boundaries | `stages/02_architecture/CONTEXT.md` |
| Understand schema / entities / migrations / state | `stages/03_data/CONTEXT.md` |
| Understand routes / controllers / services / external API | `stages/04_interfaces/CONTEXT.md` |
| Understand, or write/modify, UI/component code and its design system | `stages/05_ui/CONTEXT.md` |
| Index existing markdown docs already in the repo | `stages/06_documentation/CONTEXT.md` |
| Produce a cross-stage overview / current-focus notes | `stages/07_synthesis/CONTEXT.md` |

Canonical facts (stack, primary language, app dir) live in `shared/stack.md`
— every stage reads it, no stage re-derives it. See `_core/conventions.md`
for the Inputs/Process/Checkpoints/Audit/Outputs shape every stage
`CONTEXT.md` follows, and `_core/placeholder-syntax.md` for `{{PLACEHOLDER}}`
rules.
