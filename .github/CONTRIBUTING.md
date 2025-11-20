# Contributing to Atlas Core

This document defines the **minimum checks** and **commit style** required to complete any contribution.

All coding standards, architecture rules, and naming conventions live in **[AGENTS.md](../AGENTS.md)**.

---

## Required Validation

Run all three commands and ensure **zero errors**:

```bash
composer lint
composer analyse
composer test
```

**Definition of Done**
- Pint: no pending diffs after running.
- PHPStan: level 8 with 0 errors.
- Tests: all pass deterministically (no retries).

If any check fails, the work is **not complete**.

---

## Commit Style

Use **Conventional Commits 1.0.0**:

```
<type>[optional scope]: <short description>

[optional body]

[optional footer(s)]
```

Common types:
- `feat` — new feature
- `fix` — bug fix
- `docs` — documentation only
- `style` — formatting, no behavior change
- `refactor` — behavior‑preserving code change
- `perf` — performance improvement
- `test` — add/update tests
- `chore` — tooling/build changes

Keep commits focused and descriptive.
