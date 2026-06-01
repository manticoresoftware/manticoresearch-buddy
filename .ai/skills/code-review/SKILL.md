---
name: code-review
description: Shared Manticore gatekeeper workflow for reviewing behavior, implementation rules, security, and validation evidence.
---

# Shared Code Review Workflow

Use this workflow when manually reviewing a diff, pull request, or proposed code change with Codex or another local AI tool.

This is not a codestyle checklist. Codestyle is handled by automated tooling. AI review must protect behavior, architecture, runtime safety, security, invariants, and long-term maintainability.

Before reviewing, read:

1. `AGENTS.md`
2. `.ai/instructions/repository.md`
3. `.ai/instructions/validation.md`
4. `.ai/skills/behavior-review/SKILL.md`
5. `.ai/skills/repo-review/SKILL.md`

A plain request to “review” means run behavior-review, this code-review workflow, and the Buddy repo-review wrapper together.

## Gatekeeper mode

Reviewer operates in **Gatekeeper Mode**.

Goal: protect behavior, architecture, runtime safety, security, invariants, and long-term maintainability.

Strict enforcement > politeness.

## Required review context

A full review must include a developer-provided change summary.

The summary should explain:

- what was changed
- why it was changed
- what behavior was intended
- linked issue/task if available

Issues are often private, so a linked issue is optional. A short written summary is the required default.

If no summary is provided, do not start a full review and do not guess the intended contract. Ask the developer to provide the summary first.

Proceed without this context only if the developer explicitly declines to provide it or explicitly asks to continue anyway. In that case, clearly mark the review as limited to implementation/rule/security checks and behavior inference from code/tests only.

Use the summary to answer:

- What was the developer trying to change?
- Is the changed code relevant to that goal?
- Does the change fully cover the requested behavior?
- Does it introduce behavior outside the requested scope?

## Review modes

### Quick review

Use quick review only when the change is tiny, local, and low-risk.

Typical quick review scope:

- docs-only or comment-only change
- small typo, naming, or message adjustment
- narrow local refactor with no behavior change
- one small behavior change with obvious intent and low blast radius

Do not use quick review when the change touches architecture boundaries, shared code, Swoole/runtime state, config loading, security-sensitive behavior, dependencies, public contracts, or multiple features.

For quick review, the user's immediate request may act as the change summary if intent is obvious. Ask for a separate summary only when intent is unclear enough to affect the review.

Quick review output should be short bullet points with problems only. If no problems are found, say that no obvious issues were found within quick-review scope and mention any important limitation.

If quick review finds risk or uncertainty, switch to full review.

### Full review

Use full review for normal PRs, behavior changes, risky changes, architecture changes, runtime-state changes, security-sensitive changes, dependency changes, public-contract changes, and anything non-trivial.

Full review requires the developer summary and formal findings format below.

## Combined review orchestration

A plain request to `review`, `code review`, or `review current changes` means one combined review pass:

1. Use `behavior-review` evidence gathering to retrieve the diff, read current file state, trace real consumers, and understand behavior before judging code.
2. Apply this `code-review` workflow for behavior correctness, repository rules, security, severity, validation evidence, and formal findings.
3. Apply `repo-review` focus areas for Buddy-specific Swoole state, core/plugin boundary, config loading, invariant masking, and validation cost.

Quick/full mode controls output depth, not which workflows are skipped.

### Quick combined review output

Use only when the change qualifies for quick review.

Output:

```text
Behavior: <one sentence about what changed>
Findings:
- <problem bullet, severity if useful>
Limitations: <only if important>
```

Do not emit full behavior cards in quick mode. Still use behavior-review thinking: retrieve the diff, read changed code, and check obvious consumers before saying no problems were found.

### Full combined review output

Use for normal or risky reviews.

Output in this order:

1. **Behavior brief** — include behavior-review triage and cards, but defer the final collapsible source section until the end of the whole combined review.
2. **Findings** — use the formal code-review finding format below for code, repo, security, and validation problems.
3. **Limitations / validation evidence** — state missing developer summary, missing validation, or unverified impact when relevant.
4. **Source / evidence** — one final changed-files/source section for the whole review.

Do not duplicate the same behavior summary or source list in multiple sections.

### Missing summary in combined review

If no developer summary is provided:

- quick review may proceed when intent is obvious from the immediate request and small diff
- full review must ask for a summary unless the user explicitly asks to continue anyway
- if proceeding without a summary, label intent as inferred and mark the review limited

Behavior-review may infer intent from code only when it is clearly labeled as inference. Do not treat inferred intent as a developer-provided contract.

## Enforcement rules

- You are not a passive reviewer.
- You are responsible for protecting behavior, architecture, security, and invariants.
- If a rule is violated, it MUST be reported.
- “It works” is not a valid justification.
- Passing tests alone is not enough.
- Do not assume intent.
- Do not allow architectural drift.
- Do not silently tolerate rule violations.
- If uncertain, ask instead of assuming.

Failure to report a clear violation is a review failure.

## Review layers

### Layer 1 — Behavior and intent check

This is the first and most important review layer.

Determine the intended behavior from the developer summary, tests, docs, changed code, and previous version of the code.

Check:

- What changed in behavior?
- What did the code do before?
- What does it do now?
- Is the change related to the stated goal?
- Does the change solve the described problem?
- Does it solve only part of the problem?
- Does it introduce extra behavior not requested?
- What other paths, plugins, requests, configurations, or users could be affected?
- Are edge cases, failure paths, and lifecycle transitions affected?
- Do tests reflect the intended behavior, or only exercise implementation details?
- Are there missing tests for behavior that changed?

Use previous code, existing tests, and call paths to infer behavior. Do not only inspect the changed lines.

Findings from this layer are usually **BLOCKER** when the behavior is wrong, incomplete, unrelated to the summary, or likely to break existing behavior.

### Layer 2 — Repository rules and implementation quality

After behavior is understood, check implementation against repository rules.

Review in this order:

1. Architecture boundaries.
2. Runtime safety, especially Swoole state.
3. Config and `simdjson_decode()` compliance.
4. Invariants and defensive noise.
5. Silent failures.
6. Duplication.
7. Clarity and maintainability.

Apply `.ai/instructions/repository.md` to both new code and modified parts of existing code.

### Layer 3 — Security review

Check whether the change introduces security risk.

Review:

- secrets, tokens, passwords, or credentials committed or logged
- real or realistic-looking secrets in fixtures, snapshots, tests, or examples
- unsafe shell execution or command construction
- path traversal or unsafe filesystem access
- unsafe use of external input
- SQL/query injection risks
- sensitive data exposure in logs, errors, responses, or tests
- missing authorization/authentication checks where behavior touches access control
- insecure external network calls or unchecked third-party data

Security findings are **BLOCKER** when they expose secrets, permit injection, bypass authorization, leak sensitive data, or create confirmed exploitable behavior. Potential security concerns without a demonstrated exploit path are **IMPORTANT** unless risk is clearly high.

### Layer 4 — Validation and evidence

Check whether the change has enough evidence for its risk level.

AI review does not control commits, CI, or merge decisions. It reports whether the provided validation evidence is enough, missing, or too expensive to require by default.

Review:

- Were targeted tests added, updated, or run for changed behavior?
- For docs-only or AI-instruction-only changes, were consistency, stale terminology, links, and command accuracy checked instead of unnecessary PHP validation?
- Do existing tests still cover the previous behavior that should remain unchanged?
- Were relevant Docker-only commands run?
- Is full-suite validation necessary for this risk level, or would targeted evidence be enough?
- If validation was not run, is the limitation clearly stated?
- Are there areas that need manual verification?
- Are docs or examples updated if behavior/contracts changed?

Validation commands and environment rules are defined in `.ai/instructions/validation.md`.

Missing evidence is usually **IMPORTANT**. It becomes **BLOCKER** only when the change is risky, behavior-changing, security-sensitive, broad/cross-cutting, or likely to break runtime safety, data correctness, compatibility, or public contracts.

## Review severity rules

Every finding MUST be categorized.

### BLOCKER

Must be fixed before the reviewer can approve or recommend accepting the change.

Typical examples:

- wrong or incomplete behavior relative to the developer summary
- unrelated behavior change
- high-risk untested behavior change
- security vulnerability or sensitive data leak
- architecture boundary violation
- unsafe Swoole shared state
- config/`simdjson_decode()` violation
- silent failure
- invariant masking or internal fallback
- broken validation or likely validation breakage

### IMPORTANT

Should be fixed or explicitly justified before the reviewer recommends accepting the change.

Typical examples:

- missing tests for moderate-risk behavior
- duplication at three or more occurrences
- unnecessary type guards
- unjustified `mixed`
- unclear method boundaries
- avoidable maintainability issue

### SUGGESTION

Optional improvement.

Typical examples:

- naming clarity
- small readability improvement
- minor documentation improvement

## Mandatory review output

For full review, start with a short behavior summary:

```text
Behavior summary:
- Developer summary: <provided summary, or "not provided">
- Previous behavior: <what code/tests indicate, or "not checked">
- New behavior: <what changed>
- Potentially affected areas: <paths/features/users/configs>
```

Then list findings. Each finding must follow:

```text
[SEVERITY] — Rule: <Rule Name>

Location: file.php:line (Class::method)

Problem:
Short description.

Why it matters:
1–2 sentences maximum.

Fix:
Concrete actionable recommendation.
```

Do not rewrite entire files.

If no violations are detected, explicitly state one of:

```text
No rule violations detected.
```

or, for limited review:

```text
No rule violations detected within the limited review scope.
```

If developer summary was missing and the developer explicitly declined to provide it or asked to continue anyway, also state:

```text
Review limitation: no developer change summary was provided. Developer declined or asked to continue, so behavior-to-requirement matching was limited to inference from code/tests.
```

## Mandatory cross-check before finalizing full review

Reviewer must verify:

- [ ] Developer change summary identified, or developer explicitly declined/proceeded without it and limitation is stated.
- [ ] Previous behavior checked where relevant.
- [ ] New behavior and affected areas identified.
- [ ] Tests checked for behavior coverage, not only implementation coverage.
- [ ] `buddy-core` boundary respected.
- [ ] JSON-only configs.
- [ ] `simdjson_decode()` used.
- [ ] No silent failures.
- [ ] No Swoole shared-state risks.
- [ ] No single-use interfaces.
- [ ] No internal fallback logic.
- [ ] No redundant type guards.
- [ ] No duplication >= 3.
- [ ] No heavy dependency added.
- [ ] Security layer checked.
- [ ] Validation evidence or limitation reported.

If any checkbox fails, report it or state the review limitation.
