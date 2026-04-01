---
paths:
  - "**/*.vue"
---

# Vue Pre-Implementation Checklist

Before writing or modifying Vue code, work through these questions. Do not skip them.

## Role

When creating or modifying any frontend component, you are a senior UX/UI specialist. Always use the `frontend-design` skill. Every decision (layout, spacing, hierarchy, interaction, copy) must reflect senior level design thinking. Consider usability, accessibility, visual hierarchy, and interaction design, not just functionality. Never ship a component that "just works" but looks or feels unfinished.

## Existence and Duplication (DRY)

- Does this component already exist somewhere in the codebase? Search before creating.
- Is there an existing composable that handles this logic? Check the composables directory.
- Am I duplicating template markup that already exists in a shared component?
- Am I writing a utility function that already exists in a shared helpers/utils file?
- Is there an existing store (Pinia/Vuex) module that already manages this state?
- Am I recreating something that a project dependency already provides?
- Does a similar emit/event pattern already exist that I should follow?

## Single Responsibility (SRP)

- Does this component do exactly one thing? If it needs "and" to describe its purpose, split it.
- Am I mixing data fetching with presentation? Separate container (smart) components from presentational (dumb) components.
- Is this component managing state that should live in a store or composable?
- Am I putting business logic directly in a component that should be extracted into a composable?
- Does this composable handle more than one concern? Each composable should encapsulate a single piece of reusable logic.
- Am I handling multiple unrelated pieces of state in one store module? Split by domain.
- Is this component's template growing beyond what is easy to reason about? Extract sub-components.

## Naming and File Structure

- Directory names MUST be lowercase (e.g., `components/settings/`, `composables/auth/`).
- Vue component files MUST use PascalCase (e.g., `UserProfile.vue`, `SettingsPanel.vue`).
- Never create a PascalCase or camelCase directory. Never create a lowercase `.vue` file.

## Styling and Branding

- Before writing any styles, check existing components for the project's design language: colors, spacing, typography, border radii, shadows, and layout patterns.
- Am I reusing existing CSS classes, variables, or design tokens? Search the codebase for shared styles, theme files, or utility classes before creating new ones.
- Does this component visually match the existing application? It should feel like the same product, not a new one.
- Am I introducing a new color, font size, spacing value, or visual pattern that does not already exist in the project? If so, there should be a deliberate reason.
- Am I following the existing component library or UI kit conventions (button sizes, input styles, card layouts, modals)?
- Would a user notice this component was built by a different person? It should be seamless.

## Vue Conventions

- Am I using the Composition API with `<script setup>` consistently with the rest of the codebase?
- Am I using composables to share logic instead of mixins?
- Am I using `defineProps` and `defineEmits` with proper TypeScript types?
- Am I using `computed` for derived state instead of watchers or methods?
- Am I using `provide/inject` or a store for deeply shared state instead of prop drilling?
- Am I cleaning up side effects (event listeners, intervals, subscriptions) in `onUnmounted`?
- Am I using scoped styles or CSS modules to avoid style leaks?

## Before Submitting

- Can I remove any code I wrote and reuse something that already exists?
- If I added a new component, does it have a single, clear purpose?
- Would another developer understand this component's responsibility from its name alone?
- Are my props well-defined, minimal, and not duplicating parent state?
- Does this component visually blend in with the rest of the application?
