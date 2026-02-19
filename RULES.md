# Development Rules

Architecture patterns and usage guidelines for Manticore Buddy project.

---

## Architecture

**Shared code → buddy-core**
Code reused or potentially reusable across multiple plugins goes to `buddy-core` package. Plugin-specific logic stays in plugin.

**Configs as JSON**
All configuration and static data files stored as JSON, not PHP arrays with `require`. Clear separation of code and data.

**Simdjson only**
Use `simdjson_decode()` for all JSON decoding. Extension is available and provides performance benefits.

**Prefer lightweight dependencies**
Always prefer a lightweight library with minimal dependencies if one exists for the task. Avoid heavy frameworks when a focused package suffices. Assess dependency cost before adding.

---

## Code Structure

**No single-use interfaces**
Don't create interface if it has only one implementation. Over-engineering without benefit.

When to use what:
- Single implementation → concrete class
- Need shared code for subclasses → abstract class
- Multiple implementations exist → interface (but question if it adds value)

**No useless wrappers**
Don't create methods that just call another method without adding value. Unnecessary indirection.

**No pre-optimization**
Don't build abstractions for hypothetical future needs. Build for current requirements, refactor when actual need arises.

**Balanced method size**
Avoid extremes: too many tiny methods (1-2 lines) make code hard to follow, too few large methods make code hard to understand.

Guidelines:
- Method should do ONE thing
- If you need to comment inside a method, consider extracting that part
- If method fits on screen and is readable, it's fine
- Extract when code is duplicated or complex, not just to make methods small

**No excessive null coalescing**
Too many `??` operators indicate design problem. If you need fallbacks everywhere, fix the data structure or interface instead of patching with null checks.

Good: Use `??` for external input (request params, config)
Bad: Chain of `??` for internal data that should be properly initialized

**Use union types over mixed**
When you know possible types, use `int|float|string` instead of `mixed`. Explicit types = better IDE support, clearer contract, caught errors at runtime.

Bad:
```php
private function toNumberOrZero(mixed $value): int|float {
    if (is_int($value) || is_float($value)) { return $value; }
    if (!is_string($value)) { return 0; }
}
```
Good:
```php
private function toNumberOrZero(int|float|string $value): int|float {
    // Type already guaranteed by signature
}
```
Exception: `mixed` OK for truly dynamic data (recursive structures, serialization).

**No copy-paste beyond 2 times**
Duplicate code ≤2 times = acceptable. 3+ times = extract to shared function. Balance DRY with premature abstraction.

**No method chaining for single-use logic**
Don't chain method calls when each step is only used in that chain. Hard to debug, obscures flow. Inline the logic or keep as single method.

**No over-commenting**
Comments should explain WHY, not repeat WHAT. Each line comment is noise. Remove comments that just restate the code.

**No silent failures**
Never catch exceptions and do nothing. At minimum, log the error. Silent failures hide bugs and make debugging impossible.

**No magic strings when repeated**
String literal used once is fine. Same string repeated 2+ times should be a constant. Typos in strings cause bugs that PHP won't catch.

**Don't fight the interface**
If you need `unset($param)` to suppress unused parameter warnings, the interface is wrong. Fix the interface or accept the parameter.

---

## Principles

**KISS**
Write code that's easy to understand and modify. Avoid clever abstractions.

**DRY - Don't Repeat Yourself**
Duplicate code allowed up to 2 times. At 3+ occurrences: extract to shared function/class.

**Reuse over reinvent**
Always reuse existing functionality before implementing from scratch. If similar logic exists in buddy-core, plugin, or elsewhere, adapt and extend rather than duplicate. Check codebase first.

**YAGNI (You Aren't Gonna Need It)**
Don't build features until required. Write concrete code first, add abstractions when actually needed. "We might need this later" is not a valid reason.

---

## Runtime

**Swoole single-process**
All code runs in Swoole single-process environment. Shared state persists across requests. Be mindful of static/global state modifications.

---

## Quality

**Fail fast**
Validate inputs early. Throw exceptions immediately on invalid state. Easier debugging, prevents cascading failures.

**No magic numbers**
Use named constants for numeric/string values with meaning.

**Comments explain WHY**
Names describe purpose. Comments explain reasoning, not what code does.

**Trust your data structures**
Design interfaces and data structures that guarantee values exist. Don't patch poor design with null checks and fallbacks.

---

## When to Apply

- **New code**: Follow all rules
- **Existing code**: Apply when modifying. Don't refactor unrelated code "while you're here"
- **Ambiguity**: Ask. Don't assume. Clarify requirements before implementing

---

## Quick Reference

| Rule | Summary |
|------|---------|
| Shared code → buddy-core | Reusable code goes to buddy-core package |
| Configs as JSON | Store configs as .json, not PHP arrays |
| Simdjson only | Always use simdjson_decode() |
| Prefer lightweight deps | Choose focused packages over heavy frameworks |
| No single-use interfaces | Interface only when 2+ implementations |
| No useless wrappers | Don't create methods that just delegate |
| No pre-optimization | Build for current needs, not hypothetical |
| Balanced method size | Focused but not artificially split |
| No excessive null coalescing | Fix the source, don't patch with `??` |
| Union types over mixed | Use `int\|string` instead of `mixed` |
| No copy-paste beyond 2 | ≤2 OK, 3+ extract |
| No method chaining | Avoid for single-use logic |
| No over-commenting | WHY not WHAT |
| No silent failures | Never catch and ignore |
| No magic strings | Repeated strings → constants |
| Don't fight interface | Redesign interface instead |
| KISS | Simple > Clever |
| DRY | Extract when truly duplicated |
| Reuse over reinvent | Check existing code first |
| YAGNI | Build what's needed now |
| Swoole | Single-process, coroutine-aware |
| Fail fast | Validate early, clear errors |
| No magic numbers | Use named constants |
| Comments explain WHY | Reasoning, not restating |
| Trust data structures | Design guarantees, not fallbacks |