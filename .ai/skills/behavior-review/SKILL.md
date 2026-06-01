---
name: behavior-review
description: Generate evidence-based behavior review cards that explain what changed, why it matters, impact radius, risk, and divergence without doing line-by-line code review.
---

# Behavior Review Cards

Use this skill when the user asks for a behavior review, review cards, change brief, impact brief, or asks to explain a diff/commit/branch in terms of system behavior.

This skill is not the same as `.ai/skills/code-review/SKILL.md`.

- `behavior-review` explains the change and its consequences.
- `code-review` judges whether the change should be accepted and reports findings.
- `repo-review` applies Buddy-specific focus areas without duplicating repository rules.

A plain request to “review” means use all three together: behavior-review, code-review, and repo-review. In that combined mode, follow the orchestration and output-depth rules in `.ai/skills/code-review/SKILL.md`.

If the user explicitly asks only for behavior cards, a change brief, or an impact brief without gatekeeper review, use this skill standalone.

The diff is supporting evidence. The output is a behavioral brief for a developer who has not seen the code before.

## Inputs

Accept any of these:

- pasted raw diff
- commit hash or commit range
- branch name
- file path or path list
- request such as `read current changes`

When input is ambiguous, inspect repository state first. Default to analyzing all staged and unstaged changes.

If there is no diff, no commit, and no current change to analyze, say exactly:

```text
Nothing to brief. Give me a diff, a commit hash, or ask me to read the current changes.
```

## Retrieval protocol

Before writing cards, gather evidence. Do not summarize from diff hunks alone.

### 1. Retrieve the change

Use the available local tools to get:

- full diff for the requested scope
- commit message/log for the commit or range when available
- changed file list
- recent history for changed files when useful for detecting an ongoing series

For current changes, inspect both staged and unstaged diffs.

### 2. Understand changed code

For every function, class, constant, config, command, or public surface that changed:

- read the current file state, not just the diff hunk
- read the changed block plus surrounding context
- if a signature changed, read the full function/method body
- if a data shape changed, find where it is created, serialized, parsed, or consumed
- if config/static data changed, check the loading path and consumers

### 3. Trace impact

For every changed public symbol, exported behavior, command, config, or cross-file behavior:

- search for direct consumers/call sites/usages
- search semantically by behavior when name search is insufficient
- read at least one real consumer when claiming impact
- read one level deeper for hot paths, request lifecycle paths, Swoole shared state, auth/security paths, and config loading

Never assert impact from intuition alone.

### 4. Check cross-cutting concerns

Always check whether the change affects:

- error handling
- public contracts or interfaces
- Swoole/concurrency/runtime state
- auth or permission checks
- config, schema, serialization, or static data formats
- security-sensitive input/output/logging

These concerns deserve explicit risk or divergence only when there is a real signal, not as filler.

### 5. Project intent vs reality

Compare the stated intent from commit message, branch name, user summary, tests, docs, or surrounding code against the actual change.

Look for:

- intent mismatch: stated goal says X, code does Y
- architectural drift: change introduces a second pattern where the surrounding code uses one established pattern
- incomplete change: new field/function/path exists but is not wired, read, tested, or consumed

Only include divergence when it would matter to a pragmatic reviewer.

## Evidence and confidence

Each card must have a confidence tier based on evidence:

```text
HIGH   ●●● — read changed code and real consumers; intent matches or no message was available
MEDIUM ●●○ — read changed code, but consumer or intent evidence is partial
LOW    ●○○ — read changed code, but impact/intent could not be verified
```

Do not write a card if you did not read the actual changed code.

Claim rules:

- `X is affected` requires finding and reading X's use of the changed code.
- `Breaking change` requires finding a caller or consumer that depends on old behavior.
- `No downstream impact` requires searching for consumers and finding none, or verifying all consumers are internal/unchanged.
- `Intent was Y` requires commit/user summary or unambiguous context.
- `Risk Z` requires a traced path where Z can occur.
- `Divergence` requires reading enough surrounding code to know the established pattern.

If evidence is missing, write `Unable to determine — <what was missing>` or omit the claim.

## Grouping

One card per logical behavior change, not per file.

Group by intent:

- one rename spanning many files is one card
- feature work and its tests are one card when they share the same intent
- unrelated behavior changes are separate cards

Maximum: 5 cards.

If more than 5 independent concerns are present, write only the 5 most important cards and add:

```text
⚠️ This changeset should be split. Too many independent concerns to review safely as one unit.
```

## Pragmatic filter

Before writing any impact, risk, question, or divergence, ask:

- Would a busy engineer care about this in a real review?
- Is this a real failure mode, not a theoretical edge case?
- Is the impact felt by a real caller or user?
- Is drift likely to cause confusion or bugs, not just a style difference?
- Can the point be explained simply in one sentence?

If not, omit it.

Do not mention formatting, whitespace, import ordering, praise, or generic style concerns.

## Output format

For standalone behavior-review requests, output exactly three layers.

When this skill is used as part of a combined plain review, follow `.ai/skills/code-review/SKILL.md` for the combined output structure. Do not duplicate sections or block code-review findings with this skill's standalone `Do not output anything after the source section` rule.

### Layer 1 — Triage

Always first.

```md
## 📦 Brief: <one-line behavioral description of the changeset>
**Overall risk:** 🔴 HIGH / 🟡 MEDIUM / 🟢 LOW · **Cards:** N

| # | Change | Risk | Confidence |
|---|--------|------|------------|
| 1 | <one-line behavioral summary> | 🔴/🟡/🟢 | ●●● / ●●○ / ●○○ |
```

### Layer 2 — Cards

One per logical behavior change, max 5.

```md
### Card N of M: <behavior title>
**Confidence:** ●●● HIGH / ●●○ MEDIUM / ●○○ LOW

**INTENT**
One sentence explaining why this change exists. Use commit/user summary when available; otherwise infer carefully from code and context.

**WHAT CHANGED**
- Max 5 bullets.
- Describe system behavior, not filenames or line edits.

**IMPACT RADIUS**
- Name affected components and describe the effect.
- Mark BREAKING only with evidence.
- If isolated: `Isolated change — no downstream impact detected.`

**RISK**
🔴 HIGH / 🟡 MEDIUM / 🟢 LOW. Always present.
For LOW, one sentence is enough.
For HIGH/MEDIUM, include only traced, pragmatic failure modes.

**QUESTIONS**
Omit this section unless a decision is genuinely unclear and cannot be answered by reading more code.

**DIVERGENCE**
Omit this section unless real projection evidence exists.
Use only these signal types:
- 🔀 **Intent mismatch** — stated intent and actual behavior differ
- 🧭 **Architectural drift** — established pattern is bypassed in a way that may cause confusion or bugs
- 🧩 **Incomplete change** — change looks partially wired or part of a larger missing series

📎 **Source**
- `path/to/file.php` lines X-Y — <what this block shows>
```

Use inline code snippets only when a single condition, expression, or signature of 8 lines or fewer is essential to understand the risk or question.

### Layer 3 — Source

Always last.

```md
<details>
<summary>📂 Files changed (<N> files, ~<X> lines)</summary>

- `path/to/file.php` — <role in the change>
- `path/to/other.php` — <role in the change>

</details>
```

Do not output anything after the source section in standalone behavior-review output.

## Hard rules

- Do not ask for confirmation before writing the brief.
- Do not review line-by-line.
- Do not invent intent, impact, risks, call sites, or downstream behavior.
- Do not write cards from the diff alone.
- Do not exceed 5 cards.
- Do not pad sections with theoretical concerns.
- Do not include questions that code reading could answer.
- Always include the triage table and source section.
- Always include source references in every card.
