# Manticore Buddy repository rules

These rules apply during both code writing and code review. When writing code, use these rules as design constraints before implementation, not as an after-the-fact review checklist. Codestyle is handled by tooling; these rules protect behavior, architecture, security, invariants, runtime safety, and maintainability.

## Common code-writing and review workflow

Use the same baseline discipline when writing code and when reviewing it:

1. Understand the task request when writing code, or the developer summary when reviewing code.
2. Inspect current behavior and relevant tests before changing or judging code.
3. Identify affected paths, plugins, configs, request lifecycle state, and user-visible behavior.
4. Choose the smallest change that satisfies the task and follows these rules.
5. Add or update behavior-level tests when behavior changes.
6. Run cost-efficient final validation using `.ai/instructions/validation.md`.

Do not treat these rules as review-only. They are default writing instructions.

## Project context

Manticore Buddy is a PHP project with a Swoole runtime.

Runtime model:

- Runtime: **Swoole (single-process)**.
- Shared state persists between requests.
- Static properties, globals, and singletons behave as shared memory.
- No unintended cross-request mutation.
- No assumptions of per-request isolation.

Unsafe shared state is a **BLOCKER**.

## Permissions

Agents are strictly forbidden from changing these files:

- `./bin/*`
- `composer.json`
- `ruleset.xml`
- `phpstan.neon`
- `phpunit.xml`

## Architecture rules

### Shared code boundary

- Reusable or potentially reusable logic -> `buddy-core`.
- Plugin-specific logic -> plugin only.
- No boundary violations.

Boundary violation is a **BLOCKER**.

### Configuration and JSON parsing rules

- Configs and static data must be JSON files.
- PHP array configs using `require` or `include` are forbidden.
- All JSON parsing must use `simdjson_decode()` only.
- `json_decode()` is forbidden everywhere, including request payloads, API responses, tests, fixtures, helpers, and examples.

Any violation is a **BLOCKER**.

### Dependencies

- Prefer lightweight, focused libraries.
- Avoid heavy frameworks unless strongly justified.
- Reuse existing code before adding new logic.
- Every dependency must have clear value.

Heavy or unjustified dependency is a **BLOCKER**.

## Security rules

- Do not commit secrets, tokens, passwords, or credentials.
- Do not log secrets or sensitive request data.
- No real or realistic-looking tokens/secrets in fixtures, snapshots, tests, or examples.
- Treat external input and third-party data as untrusted.
- Avoid shell execution; if unavoidable, use safe argument handling and justify it.
- Validate file paths before filesystem access to avoid traversal.
- Use safe query APIs and avoid string-built queries with untrusted data.
- Preserve authorization and authentication checks when changing request behavior.

Security vulnerabilities must not be introduced. During review, confirmed exploitable security issues are **BLOCKER**. Potential security concerns without a demonstrated exploit path are **IMPORTANT** unless risk is clearly high.

## Code structure rules

### No single-use interfaces

- Interface requires two or more meaningful implementations.
- Otherwise use a concrete or abstract class.
- No speculative abstractions.

Single-use interface is a **BLOCKER**.

### No defensive programming noise

The project does not allow cargo-cult defensive programming.

- We control our data.
- We design our invariants.
- We fail fast on real errors.
- We do not code against imaginary states.

#### No internal fallbacks

Do not introduce fallback logic for internal data.

- Do not silently substitute defaults.
- Do not mask missing required fields.
- Do not auto-correct invalid internal state.
- Do not continue in degraded mode.

If required data is missing:

- That is a bug.
- Validate and throw.

Fallback masking invariant violation is a **BLOCKER**.
Unjustified fallback is a **BLOCKER**.

Fallbacks are allowed only for:

- External input.
- Third-party data.
- Explicit backward compatibility requirements.

#### No redundant type guards

Do not add runtime type checks when type is already guaranteed by:

- Function signatures.
- Strict typing.
- Internal invariants.
- Controlled data flow.
- PHP native behavior.

Do not re-validate what is already guaranteed by contract.

Redundant type paranoia:

- Adds noise.
- Reduces clarity.
- Suggests lack of trust in the design.
- Hides real logic.

Unnecessary type guards are **IMPORTANT**.
If they mask invariant violations, they are **BLOCKER**.

#### No imaginary states

Do not guard against states that cannot happen by design.

If a state truly can happen:

- Make it explicit in the type system.
- Or validate and fail immediately.

Do not silently compensate.

### No silent failures

- Never catch and ignore exceptions.
- Errors must be logged, handled, or rethrown.
- No hidden degradation.

Silent failure is a **BLOCKER**.

### Duplication

- Duplication <= 2 occurrences is acceptable.
- Three or more occurrences must extract shared logic.

Duplication >= 3 is **IMPORTANT**.

### No pre-optimization

- No speculative abstractions.
- No “might need later” logic.
- No architecture for hypothetical futures.

Speculative abstraction is **IMPORTANT**.
If it complicates architecture, it is **BLOCKER**.

### Method design

- One clear responsibility per method.
- Avoid micro-fragmentation.
- Avoid large multi-responsibility blocks.
- Extract only when justified.
- Readability > artificial structure.

### Null coalescing

- `??` is allowed only for external input.
- Internal null-patching indicates design flaw.
- Do not patch poor invariants with null-coalescing.

Internal null patching is a **BLOCKER**.

### Types

- Prefer union types over `mixed`.
- `mixed` is allowed only for truly dynamic structures.
- Make contracts explicit.

Unjustified `mixed` is **IMPORTANT**.

### Method chaining

Avoid long single-use chains that:

- Obscure logic.
- Reduce debuggability.
- Hide state transitions.

### Magic values

- Repeated strings two or more times -> constants.
- Meaningful numeric values -> named constants.
- Avoid typo-prone magic literals.

Repeated magic values are **IMPORTANT**.

### Interface mismatch

If the implementation fights the interface:

- The interface is wrong.
- Redesign it.
- Do not patch around it.

## Quality principles

- **KISS** -> clarity over cleverness.
- **DRY** -> extract only at real duplication.
- **YAGNI** -> no speculative design.
- **Fail Fast** -> validate early.
- **Trust Data Structures** -> design guarantees, not fallbacks.

## Code style guidelines

- Follow PSR-12.
- Use strict types where applicable.
- Prefer explicit types over implicit casting.
- Use descriptive, intention-revealing names.
- No hidden side effects.
- Keep constructor dependencies minimal and explicit.
- Avoid implicit state mutation.

Ignore minor formatting unless it hides a real issue.

## When to ask

Ask before implementing or approving when:

- the task summary is missing or ambiguous enough to change behavior or architecture
- a rule exception seems necessary
- the change requires editing forbidden files
- Docker validation or required permissions are unavailable
- validation scope is unclear or potentially expensive
- tests/code cannot answer an important behavior question

Do not ask when the requirement is clear, the choice is local, and existing code patterns answer the question.

## Rule exceptions

Rules are strict by default. Exceptions are allowed only when:

1. the task explicitly requires it or a maintainer approves it
2. the reason is documented in the task, PR, review, or code where appropriate
3. a simpler compliant design is not sufficient
4. the exception is called out during review

Without that justification, enforce the rule as written.

These rules use review severity labels such as **BLOCKER** and **IMPORTANT**. During code writing, treat them as design constraints: do not introduce code that would trigger those labels. During review, use the labels to communicate risk and recommendation. They do not grant agents authority over commits, CI, or merges.

## Scope rules

- Apply all rules to new code.
- Apply rules to modified parts of existing code.
- Do not refactor unrelated legacy areas.
- If requirements are unclear, ask instead of guessing.
