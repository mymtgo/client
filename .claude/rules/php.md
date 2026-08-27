---
paths:
  - "**/*.php"
---

# PHP Conventions (project-specific)

These are the rules that differ from, or are not covered by, the Laravel/PHP
defaults already described in the Boost guidelines.

- Avoid `DB::` — prefer `Model::query()`
- Use `config()` not `env()` outside config files
- Eloquent model for a match is `MtgoMatch`, never `Match`
- `app/Actions/` holds business logic as single-responsibility invokable classes
- Service classes are for wrapping 3rd party APIs only — never for organising
  application logic (see `conventions.md`)

## Testing

- Test idempotency — same input twice = same result
- Test partial/malformed input scenarios (logs are hostile input)
- Never remove tests without approval
