# AI instruction workflows

This directory contains shared instruction and workflow files for AI coding agents.

## v1 scope

The initial structure is intentionally small:

- `../AGENTS.md` is the small canonical entrypoint.
- `instructions/repository.md` defines detailed repository rules for both code writing and review.
- `instructions/validation.md` defines Docker-only validation rules.
- `skills/behavior-review/SKILL.md` defines evidence-based behavior review cards for explaining changesets without line-by-line review.
- `skills/code-review/SKILL.md` defines the shared gatekeeper review workflow with behavior, rules, security, and validation layers.
- `skills/repo-review/SKILL.md` defines the small Manticore Buddy-specific review wrapper and focus areas.

A plain request to “review” means all three review workflows: behavior-review, code-review, and repo-review. Use the combined review orchestration in `skills/code-review/SKILL.md`; quick/full mode controls output depth, not which workflows are skipped.

Do not add behavior analytics, registry files, validation tooling, `.claude/` files, or nested `AGENTS.md` files unless maintainers explicitly approve them.

## Workflow precedence

1. `../AGENTS.md`
2. nearest nested `AGENTS.md`, if introduced later
3. `.ai/instructions/*.md`
4. active `.ai/skills/*/SKILL.md`
5. tool-specific adapter files

Tool-specific files should route agents to `AGENTS.md`; they should not duplicate rules.
