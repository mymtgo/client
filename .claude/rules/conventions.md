# General Conventions

These apply to all code in all projects.

## DRY (Don't Repeat Yourself)

Before creating anything new, search the codebase for existing implementations. Prefer reusing and extending existing code over creating new classes, components, functions, or utilities. If something similar exists, adapt it rather than duplicating it.

## SRP (Single Responsibility Principle)

Every class, component, function, and module should have exactly one reason to exist. If you need the word "and" to describe what it does, it should be split. Name things so their single purpose is obvious.

## Service Classes

Service classes are reserved for wrapping 3rd party APIs and integrations only. Never create a service class to separate or organize application logic. Use actions, jobs, or other dedicated classes instead.
