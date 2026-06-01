# Manticore Buddy — AGENTS.md

This is the canonical entrypoint for AI coding agents working in this repository.

Keep this file small. Detailed rules live in referenced files under `.ai/`.

## Mandatory first reads

Before changing or reviewing code, read:

1. `.ai/instructions/repository.md` — architecture, Swoole runtime, config, code-quality, and scope rules for code writing and review.
2. `.ai/instructions/validation.md` — Docker-only build, lint, static-analysis, and test rules.

For code, diff, branch, or PR review requests, perform all review workflows and also read:

3. `.ai/skills/behavior-review/SKILL.md` — evidence-based behavior cards explaining what changed, impact, risk, and divergence.
4. `.ai/skills/code-review/SKILL.md` — gatekeeper review workflow, behavior/rules/security/validation layers, severity model, and finding format.
5. `.ai/skills/repo-review/SKILL.md` — Manticore Buddy-specific review wrapper and focus areas.

A plain request to “review” means behavior review + code review + repo-specific review. Use the combined review orchestration in `.ai/skills/code-review/SKILL.md`; quick/full mode controls output depth, not which review workflows are skipped.

## Non-negotiable rules

- Do not edit `./bin/*`, `composer.json`, `ruleset.xml`, `phpstan.neon`, or `phpunit.xml`.
- Do not commit unless explicitly instructed.
- When running build, lint, static analysis, Composer, or tests, run them inside the Docker `buddy` service only.
- Treat static properties, globals, and singletons as shared state under Swoole.
- All JSON parsing must use `simdjson_decode()` only; `json_decode()` is forbidden everywhere.
- Do not introduce silent failures, internal fallback masking, or unsafe cross-request mutable state.

## Tool-specific adapters

`CLAUDE.md`, `.github/copilot-instructions.md`, and optional `.cursor/rules/*` files are discovery adapters only.

They must not define independent repository rules. They should route agents here.

## Instruction precedence

1. root `AGENTS.md`
2. nearest nested `AGENTS.md`, if introduced later, only for files under that directory
3. `.ai/instructions/*.md`
4. `.ai/skills/*/SKILL.md` for the active workflow
5. tool-specific adapter files

If instructions conflict, follow the higher-precedence file and report the conflict.
