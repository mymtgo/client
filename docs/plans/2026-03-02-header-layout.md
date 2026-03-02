# Header-Based Layout Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the sidebar navigation with a horizontal header layout — dark branding bar, navigation tabs, full-width content.

**Architecture:** Two new components (`AppHeader.vue`, `AppNav.vue`) replace the sidebar and site header. `AppLayout.vue` is rewritten as a simple vertical stack. Pages that pass `breadcrumbs` get that prop removed. `StatusBar.vue` is unchanged.

**Tech Stack:** Vue 3, Inertia.js v2, Tailwind CSS v4, Wayfinder routes, Lucide icons

**Design doc:** `docs/plans/2026-03-02-header-layout-design.md`

---

### Task 1: Create AppHeader component

**Files:**
- Create: `resources/js/components/AppHeader.vue`

**Step 1: Create the component**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Settings } from 'lucide-vue-next';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';
</script>

<template>
    <header class="flex h-12 shrink-0 items-center justify-between bg-sidebar px-4 text-sidebar-foreground">
        <Link :href="DashboardController.url()" class="text-base font-semibold tracking-tight">
            mymtgo
        </Link>

        <Link
            :href="SettingsIndexController.url()"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
        >
            <Settings class="size-4" />
        </Link>
    </header>
</template>
```

**Step 2: Verify no syntax errors**

Run: `npx vue-tsc --noEmit 2>&1 | head -20`
Expected: No errors related to AppHeader

**Step 3: Commit**

```bash
git add resources/js/components/AppHeader.vue
git commit -m "feat: add AppHeader component for horizontal layout"
```

---

### Task 2: Create AppNav component

**Files:**
- Create: `resources/js/components/AppNav.vue`

**Step 1: Create the component**

The nav reuses the same route controllers and `isActive` logic from the old `AppSidebar.vue`.

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Layers, Trophy, Swords } from 'lucide-vue-next';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import LeaguesIndexController from '@/actions/App/Http/Controllers/Leagues/IndexController';
import OpponentsIndexController from '@/actions/App/Http/Controllers/Opponents/IndexController';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'Decks',     icon: Layers,          href: DecksIndexController.url() },
    { label: 'Leagues',   icon: Trophy,           href: LeaguesIndexController.url() },
    { label: 'Opponents', icon: Swords,            href: OpponentsIndexController.url() },
];

const isActive = (href: string) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <nav class="flex h-10 shrink-0 items-center gap-1 border-b bg-background px-4">
        <Link
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="isActive(item.href)
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-accent-foreground'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
    </nav>
</template>
```

**Step 2: Verify no syntax errors**

Run: `npx vue-tsc --noEmit 2>&1 | head -20`
Expected: No errors related to AppNav

**Step 3: Commit**

```bash
git add resources/js/components/AppNav.vue
git commit -m "feat: add AppNav component for horizontal tab navigation"
```

---

### Task 3: Rewrite AppLayout

**Files:**
- Modify: `resources/js/AppLayout.vue`

**Step 1: Replace the layout**

The new layout is a simple vertical stack — no sidebar provider, no sidebar inset.

```vue
<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import StatusBar from '@/components/StatusBar.vue';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <div class="flex h-screen flex-col">
        <AppHeader />
        <AppNav />
        <div class="flex flex-1 flex-col overflow-y-auto">
            <slot />
        </div>
        <StatusBar />
    </div>
</template>
```

Key changes:
- Removed `SidebarProvider`, `SidebarInset`, `AppSidebar`, `SiteHeader` imports
- Removed `breadcrumbs` prop
- Kept `title` prop (used by pages for `<Head>` / document title)
- Simple `div` wrapper with `h-screen flex flex-col`

**Step 2: Verify no syntax errors**

Run: `npx vue-tsc --noEmit 2>&1 | head -20`
Expected: No errors related to AppLayout

**Step 3: Commit**

```bash
git add resources/js/AppLayout.vue
git commit -m "feat: rewrite AppLayout to use header-based navigation"
```

---

### Task 4: Remove breadcrumbs from pages

**Files:**
- Modify: `resources/js/pages/decks/Show.vue:113` — remove `:breadcrumbs` prop
- Modify: `resources/js/pages/matches/Show.vue:54-58` — remove `:breadcrumbs` prop
- Modify: `resources/js/pages/games/Show.vue:14-17` — remove `:breadcrumbs` prop

**Step 1: Update decks/Show.vue**

Change line 113 from:
```vue
<AppLayout :title="deck.name" :breadcrumbs="[{ label: 'Decks', href: DecksIndexController().url }, { label: deck.name }]">
```
To:
```vue
<AppLayout :title="deck.name">
```

Also remove the `DecksIndexController` import if it was only used for breadcrumbs. Check first — it may be used elsewhere in the file.

**Step 2: Update matches/Show.vue**

Change lines 52-59 from:
```vue
<AppLayout
    :title="`vs ${match.opponentName ?? 'Unknown'}`"
    :breadcrumbs="[
        { label: 'Decks', href: DecksIndexController().url },
        { label: deck?.name ?? '—', href: deck ? DeckShowController({ deck: deck.id }).url : undefined },
        { label: `vs ${match.opponentName ?? 'Unknown'}` },
    ]"
>
```
To:
```vue
<AppLayout :title="`vs ${match.opponentName ?? 'Unknown'}`">
```

Check if `DecksIndexController` and `DeckShowController` imports are used elsewhere before removing.

**Step 3: Update games/Show.vue**

Change lines 13-18 from:
```vue
<AppLayout
    :breadcrumbs="[
        { label: 'Match', href: MatchShowController({ id: game.match_id }).url },
        { label: 'Game Replay' },
    ]"
>
```
To:
```vue
<AppLayout title="Game Replay">
```

Check if `MatchShowController` import is used elsewhere before removing.

**Step 4: Verify no syntax errors**

Run: `npx vue-tsc --noEmit 2>&1 | head -20`
Expected: No errors

**Step 5: Commit**

```bash
git add resources/js/pages/decks/Show.vue resources/js/pages/matches/Show.vue resources/js/pages/games/Show.vue
git commit -m "refactor: remove breadcrumbs prop from pages"
```

---

### Task 5: Delete old sidebar and header components

**Files:**
- Delete: `resources/js/components/AppSidebar.vue`
- Delete: `resources/js/components/SiteHeader.vue`

**Step 1: Verify no other files import these components**

Run: `grep -r "AppSidebar\|SiteHeader" resources/js/ --include="*.vue" --include="*.ts"`
Expected: No results (the old AppLayout references were removed in Task 3)

**Step 2: Delete the files**

```bash
rm resources/js/components/AppSidebar.vue resources/js/components/SiteHeader.vue
```

**Step 3: Verify build works**

Run: `npx vite build 2>&1 | tail -10`
Expected: Build succeeds with no missing module errors

**Step 4: Commit**

```bash
git add -u resources/js/components/AppSidebar.vue resources/js/components/SiteHeader.vue
git commit -m "chore: remove unused AppSidebar and SiteHeader components"
```

---

### Task 6: Visual verification

**Step 1: Start dev server and verify layout**

Run: `npm run dev` (or `npx vite`)

Verify in the app:
- Dark header bar with "mymtgo" left, settings gear right
- Navigation tabs below: Dashboard, Decks, Leagues, Opponents
- Active tab highlighted on correct page
- Content is full width
- StatusBar visible at bottom
- Light and dark mode both work
- Navigation links all work
- Settings gear links to settings page

**Step 2: Final commit if any tweaks needed**
