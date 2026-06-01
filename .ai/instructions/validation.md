# Manticore Buddy validation rules

## Docker-only rule

All build, dependency, lint, static-analysis, and test commands must run inside the Docker `buddy` service.

All tests, builds, linting, static analysis, and Composer operations must run strictly inside the Docker `buddy` service from `docker-compose-dev.yml`.

Agents cannot run PHP, Composer, PHPUnit, PHPStan, codestyle, or project validation directly on the host machine.

The only allowed host-side validation commands are Docker Compose commands that execute inside the `buddy` service.

## Commands

Start container:

```bash
docker compose -f docker-compose-dev.yml up -d buddy
```

Install dependencies inside Docker when needed:

```bash
docker compose -f docker-compose-dev.yml exec buddy composer install
```

Run all tests inside Docker:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/test
```

Run a single test inside Docker:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/test path/to/TestFile.php
```

Code style check inside Docker:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/codestyle
```

Code style fix inside Docker:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/codestyle-fix
```

Static analysis inside Docker:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/codeanalyze
```

`./bin/codeanalyze` runs PHPStan level 9 with strict type checking.
`./bin/codestyle` enforces `ruleset.xml`.

## Code-writing finalizer

AI agents do not control commits, CI, or merges. Validation is a finalizer for code-writing tasks, not a merge gate controlled by the agent.

After adding or changing files, run the smallest useful validation set inside Docker:

1. Run targeted tests for changed behavior when a relevant test file or narrow test path is known.
2. For PHP code changes, run static analysis or codestyle when the change touches typed signatures, control flow, dependencies, config loading, shared code, or more than a trivial local branch.
3. Skip PHP test/static/codestyle validation by default for docs-only, comments-only, or AI-instruction-only changes unless the user asks or the docs change includes executable commands that need verification.
4. Run the full test suite only when the change is broad, cross-cutting, risky, or no reliable targeted test exists.
5. If validation would be expensive or unclear, ask the user which validation level they want.

Prefer cost-efficient evidence over blindly running the whole suite for every small change.

## Composer usage

Do not run `composer install` as part of every validation pass.

Run Composer only when:

- dependencies are missing
- vendor state is stale
- dependency files changed outside the AI task
- the user asks for a clean validation run

Do not edit `composer.json` to fix validation or dependency problems unless explicitly instructed.

## Review validation evidence

During review, check what validation evidence is provided or reasonably needed.

A review may recommend full-suite validation when the change is broad, risky, security-sensitive, affects runtime state, or touches shared infrastructure. Do not require full-suite validation for every small/local change by default.

When validation is missing, report it as a limitation or finding according to risk:

- low-risk local change: limitation or **SUGGESTION**
- moderate behavior change: usually **IMPORTANT**
- risky/cross-cutting/security/runtime change: may be **BLOCKER**

For documentation-only or AI-instruction-only changes, prefer checking consistency, stale terminology, links, and command accuracy over running PHP validation.

## Failed permissions

If Docker, PHP, linters, test tooling, or required permissions are not accessible:

- Ask for permissions.
- Do not try to fix host setup.
- Do not edit forbidden project configuration files to work around access issues.

## Full validation set

Use this set when the user asks for full validation or when risk justifies it:

```bash
docker compose -f docker-compose-dev.yml exec buddy ./bin/test
docker compose -f docker-compose-dev.yml exec buddy ./bin/codestyle
docker compose -f docker-compose-dev.yml exec buddy ./bin/codeanalyze
```

Run Composer first only when needed by the Composer usage rules above.

Risk of breaking tests, static analysis, or linting must be reported. Severity depends on scope and risk, as described above.
