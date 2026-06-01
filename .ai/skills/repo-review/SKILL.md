---
name: repo-review
description: Manticore Buddy-specific review wrapper that routes reviewers to the shared rules without duplicating them.
---

# Manticore Buddy Review Workflow

Use this workflow for Manticore Buddy code review.

Before using this workflow, read:

1. `AGENTS.md`
2. `.ai/instructions/repository.md`
3. `.ai/instructions/validation.md`
4. `.ai/skills/behavior-review/SKILL.md`
5. `.ai/skills/code-review/SKILL.md`

A plain request to “review” means run behavior-review, code-review, and this Buddy repo-review wrapper together using the combined review orchestration in `.ai/skills/code-review/SKILL.md`. Quick/full mode controls output depth, not whether Buddy focus areas are checked.

## Source of truth

This file must not duplicate repository rules.

The authoritative Buddy rules live in `.ai/instructions/repository.md` and `.ai/instructions/validation.md`. The shared review process lives in `.ai/skills/code-review/SKILL.md`.

If this file conflicts with any of those files, the referenced instruction or workflow file wins.

When changing a Buddy rule, update the authoritative file first. Keep this file as a small Buddy-specific review wrapper.

## Buddy-specific review stance

Review is not codestyle. Codestyle is automated by `./bin/codestyle`.

For full review, use the shared review layers from `.ai/skills/code-review/SKILL.md` and apply all rules from `.ai/instructions/repository.md`.

For quick review, use short bullet points with problems only. Do not apply the full workflow to tiny/local changes unless risk or uncertainty appears.

## Buddy-specific focus areas

While applying the shared rules, pay special attention to these Buddy risks:

1. **Swoole runtime state**
   - Treat request-specific static/global/singleton state as high-risk.
   - Think about cross-request leakage, not only current request behavior.

2. **Core/plugin boundary**
   - Check whether logic belongs in `buddy-core` or in a plugin.
   - Do not let convenience edits blur reusable logic and plugin-specific logic.

3. **Configuration loading**
   - Config and static data handling is a common source of rule violations.
   - Confirm JSON/static-data handling follows the authoritative repository rules.

4. **Invariant masking**
   - Watch for internal fallbacks, null-patching, swallowed exceptions, and guards for impossible states.
   - These often hide real Buddy bugs instead of fixing them.

5. **Validation cost**
   - For code-writing tasks, prefer targeted Docker validation.
   - For review tasks, judge whether evidence is appropriate for the risk level.
   - Do not require full-suite validation for every small/local change by default.

## Before saying no problems were found

For full review, verify that:

- developer summary is available, or the review is explicitly marked limited
- previous and new behavior were considered where relevant
- Buddy-specific focus areas above were checked
- security impact was considered
- validation evidence or limitation was reported according to risk

For quick review, keep the response concise and report only obvious issues, uncertainty, or important limitations.
