# Match Context Menu Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat right-click context menu on match rows with a grouped menu that has an inline searchable archetype submenu, eliminating the dialog round-trip for single-match archetype selection.

**Architecture:** Extract the current inline `ContextMenu` block from `MatchesTable.vue` into a new `MatchRowContextMenu.vue` component. Create a new `ArchetypePicker.vue` submenu containing a search input, pinned fallback archetypes (Homebrew/Rogue), and a scrollable filtered list of regular archetypes. Extract shared archetype-splitting logic into a `useArchetypeSplit` composable used by both the picker and the existing bulk dialog.

**Tech Stack:** Vue 3 (Composition API + `<script setup>`), Inertia v2, reka-ui (`ContextMenuSub*`), TypeScript, Tailwind v4, shadcn-vue UI components, Wayfinder for route URLs.

**Spec reference:** `docs/superpowers/specs/2026-04-26-match-context-menu-redesign-design.md`

**Implementation deviation from spec:** The spec proposed using shadcn `Command` for the picker. After reviewing component code, this conflicts with reka-ui's nested menu keyboard nav (two roving-tabindex systems competing). Plan uses a plain `<Input>` + manual filter + `<ContextMenuItem>` rows instead. Fallbacks render as static `ContextMenuItem`s above the scrollable filtered section. Keyboard nav comes free from reka-ui; input blocks parent typeahead via `@keydown.stop`.

---

## File Structure

**New:**
- `resources/js/composables/useArchetypeSplit.ts` — pure logic for format-filter + fallback/regular split + search filter for regular only.
- `resources/js/components/matches/ArchetypePicker.vue` — submenu content: search input + pinned fallbacks + scrollable filtered regular list.
- `resources/js/components/matches/MatchRowContextMenu.vue` — wraps a row's content in `ContextMenuTrigger` and renders the full menu (notes / archetype group / remove).

**Modified:**
- `resources/js/components/matches/MatchesTable.vue` — replace inline `ContextMenu` block (lines 181-267) with `<MatchRowContextMenu>`. Pass match + archetypes + handlers via props/emits.
- `resources/js/components/matches/SetArchetypeDialog.vue` — adopt `useArchetypeSplit` composable. No UI/UX change.

**Untouched:** All controllers, DTOs, routes.

---

## Task 1: Create `useArchetypeSplit` composable with tests

**Files:**
- Create: `resources/js/composables/useArchetypeSplit.ts`
- Create: `resources/js/composables/__tests__/useArchetypeSplit.test.ts` (or co-located if convention differs — verify before creating; if no JS test infra exists, skip the test file and rely on visual verification)

**Note:** Check `package.json` for `vitest` / `jest` script. If neither exists, do NOT add JS test infra in this plan — skip JS test creation and rely on the existing PHP test suite for backend behavior plus manual UI verification. Note this in the commit message.

- [ ] **Step 1: Inspect package.json for JS test runner**

```bash
grep -E '"(vitest|jest|test)"' package.json
```

If `vitest` is present, write the test file. If not, skip Step 2 and Step 3.

- [ ] **Step 2 (only if vitest exists): Write the failing test**

Create `resources/js/composables/__tests__/useArchetypeSplit.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';

const arch = (overrides: Partial<App.Data.Front.ArchetypeData> = {}): App.Data.Front.ArchetypeData =>
    ({
        id: 1,
        name: 'Test',
        format: 'modern',
        colorIdentity: [],
        isFallback: false,
        ...overrides,
    }) as App.Data.Front.ArchetypeData;

describe('useArchetypeSplit', () => {
    it('separates fallbacks from regular archetypes', () => {
        const archetypes = ref([
            arch({ id: 1, name: 'Homebrew', isFallback: true }),
            arch({ id: 2, name: 'Burn', format: 'modern' }),
        ]);
        const { fallbacks, regular } = useArchetypeSplit(archetypes, ref('CMODERN'), ref(''));
        expect(fallbacks.value.map((a) => a.id)).toEqual([1]);
        expect(regular.value.map((a) => a.id)).toEqual([2]);
    });

    it('filters regular archetypes by format using formatMap', () => {
        const archetypes = ref([
            arch({ id: 1, name: 'Burn', format: 'modern' }),
            arch({ id: 2, name: 'Storm', format: 'legacy' }),
        ]);
        const { regular } = useArchetypeSplit(archetypes, ref('CMODERN'), ref(''));
        expect(regular.value.map((a) => a.id)).toEqual([1]);
    });

    it('does not filter fallbacks by format', () => {
        const archetypes = ref([arch({ id: 1, name: 'Homebrew', isFallback: true, format: 'pauper' })]);
        const { fallbacks } = useArchetypeSplit(archetypes, ref('CMODERN'), ref(''));
        expect(fallbacks.value.map((a) => a.id)).toEqual([1]);
    });

    it('search filters only regular archetypes (case-insensitive)', () => {
        const archetypes = ref([
            arch({ id: 1, name: 'Homebrew', isFallback: true }),
            arch({ id: 2, name: 'Burn', format: 'modern' }),
            arch({ id: 3, name: 'Tron', format: 'modern' }),
        ]);
        const { fallbacks, regular } = useArchetypeSplit(archetypes, ref('CMODERN'), ref('bur'));
        expect(fallbacks.value.map((a) => a.id)).toEqual([1]);
        expect(regular.value.map((a) => a.id)).toEqual([2]);
    });

    it('returns all formats when format is null', () => {
        const archetypes = ref([
            arch({ id: 1, name: 'Burn', format: 'modern' }),
            arch({ id: 2, name: 'Storm', format: 'legacy' }),
        ]);
        const { regular } = useArchetypeSplit(archetypes, ref(null), ref(''));
        expect(regular.value.map((a) => a.id)).toEqual([1, 2]);
    });
});
```

Run: `npx vitest run resources/js/composables/__tests__/useArchetypeSplit.test.ts`
Expected: FAIL with "Cannot find module '@/composables/useArchetypeSplit'".

- [ ] **Step 3: Implement the composable**

Create `resources/js/composables/useArchetypeSplit.ts`:

```typescript
import { computed, type Ref } from 'vue';

const formatMap: Record<string, string> = {
    CMODERN: 'modern',
    CPAUPER: 'pauper',
    CLEGACY: 'legacy',
    CVINTAGE: 'vintage',
    CPREMODERN: 'premodern',
};

export function useArchetypeSplit(
    archetypes: Ref<App.Data.Front.ArchetypeData[]>,
    format: Ref<string | null>,
    search: Ref<string>,
) {
    const matchesFormat = (a: App.Data.Front.ArchetypeData): boolean => {
        if (a.isFallback) {
            return true;
        }
        if (!format.value) {
            return true;
        }
        const mapped = formatMap[format.value] ?? format.value.toLowerCase();
        return a.format === mapped;
    };

    const matchesSearch = (a: App.Data.Front.ArchetypeData): boolean => {
        const q = search.value.toLowerCase().trim();
        if (!q) {
            return true;
        }
        return a.name.toLowerCase().includes(q);
    };

    const formatFiltered = computed(() => archetypes.value.filter(matchesFormat));

    const fallbacks = computed(() => formatFiltered.value.filter((a) => a.isFallback));

    const regular = computed(() => formatFiltered.value.filter((a) => !a.isFallback && matchesSearch(a)));

    return { fallbacks, regular };
}
```

- [ ] **Step 4 (only if vitest exists): Run tests, verify pass**

Run: `npx vitest run resources/js/composables/__tests__/useArchetypeSplit.test.ts`
Expected: All 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/composables/useArchetypeSplit.ts
# and the test file if created
git commit -m "Add useArchetypeSplit composable

Extracts format-filter + fallback/regular split + search filter
logic shared between archetype picker submenu and bulk dialog."
```

---

## Task 2: Refactor `SetArchetypeDialog.vue` to use the composable

**Files:**
- Modify: `resources/js/components/matches/SetArchetypeDialog.vue`

This refactor must NOT change UI or behavior. It only swaps the inline filter/split logic for the composable. Adopting it now (before writing the picker) avoids drift between the two consumers.

- [ ] **Step 1: Replace inline filter logic with composable**

Open `resources/js/components/matches/SetArchetypeDialog.vue`. Replace lines 1-63 (script setup top through `splitArchetypes` computed) with:

```vue
<script setup lang="ts">
import { ref, computed, toRef } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import ManaSymbols from '@/components/ManaSymbols.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import BulkUpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/BulkUpdateArchetypeController';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';

const emit = defineEmits<{
    archetypeSet: [];
}>();

const props = defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const open = ref(false);
const matchId = ref<number | null>(null);
const matchIds = ref<number[]>([]);
const matchFormat = ref<string | null>(null);
const search = ref('');

const isBulkMode = computed(() => matchIds.value.length > 0);

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), matchFormat, search);
```

- [ ] **Step 2: Update template to use `fallbacks` and `regular` refs directly**

In the `<template>` block, replace the body of `<div class="flex-1 overflow-y-auto space-y-0.5">` (lines 137-171 of original) with:

```vue
<div class="flex-1 overflow-y-auto space-y-0.5">
    <template v-if="fallbacks.length">
        <Button
            v-for="archetype in fallbacks"
            :key="archetype.id"
            variant="ghost"
            class="w-full justify-between italic text-muted-foreground"
            :disabled="singleForm.processing || bulkForm.processing"
            @click="selectArchetype(archetype.id)"
        >
            <span class="flex-1 text-left">{{ archetype.name }}</span>
            <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide">System</span>
        </Button>
        <div class="my-1 border-t border-border" />
    </template>

    <Button
        v-for="archetype in regular"
        :key="archetype.id"
        variant="ghost"
        class="w-full justify-between"
        :disabled="singleForm.processing || bulkForm.processing"
        @click="selectArchetype(archetype.id)"
    >
        <span class="flex-1 text-left">{{ archetype.name }}</span>
        <ManaSymbols :symbols="archetype.colorIdentity" />
    </Button>

    <p
        v-if="fallbacks.length === 0 && regular.length === 0"
        class="py-4 text-center text-sm text-muted-foreground"
    >
        No archetypes found.
    </p>
</div>
```

Note: the original dialog applied the search filter to **both** fallbacks and regular. The new composable only filters regular. This is intentional alignment with the spec (search filters scrollable list only). Verify with the user this dialog UX change is acceptable; if not, revert and add a separate `searchFallbacks` flag to the composable.

**STOP here for user confirmation before continuing Task 2.** Verify this UX shift in the bulk dialog is OK.

- [ ] **Step 3: Run existing tests**

```bash
php artisan test --compact --filter=Archetype
```

Expected: any tests touching archetype controllers still pass (controller behavior unchanged).

- [ ] **Step 4: Manually verify dialog**

Open the app, trigger bulk archetype dialog (multi-select rows → "Set archetype" toolbar). Verify:
- Fallbacks render at top with "System" badge.
- Regular archetypes below the divider.
- Search filters regular only.
- Selecting works, dialog closes.

- [ ] **Step 5: Run pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/components/matches/SetArchetypeDialog.vue
git commit -m "Refactor SetArchetypeDialog to use useArchetypeSplit composable

Search now filters scrollable regular archetypes only, matching the
incoming context menu picker behavior."
```

---

## Task 3: Create `ArchetypePicker.vue` submenu component

**Files:**
- Create: `resources/js/components/matches/ArchetypePicker.vue`

This is the searchable submenu content rendered inside `ContextMenuSubContent` in Task 4.

- [ ] **Step 1: Write the component**

Create `resources/js/components/matches/ArchetypePicker.vue`:

```vue
<script setup lang="ts">
import { ref, toRef, watch, nextTick } from 'vue';
import { ContextMenuItem, ContextMenuSeparator } from '@/components/ui/context-menu';
import { Input } from '@/components/ui/input';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Check } from 'lucide-vue-next';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';

const props = defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
    format: string | null;
    currentArchetypeId: number | null;
    open: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    select: [archetypeId: number];
}>();

const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), toRef(props, 'format'), search);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            search.value = '';
            nextTick(() => searchInput.value?.focus());
        }
    },
);

const onSelect = (archetypeId: number) => {
    if (props.disabled) {
        return;
    }
    emit('select', archetypeId);
};
</script>

<template>
    <div class="flex w-72 flex-col">
        <div class="border-b p-1">
            <Input
                ref="searchInput"
                v-model="search"
                placeholder="Search archetypes..."
                class="h-8"
                @keydown.stop
                @click.stop
            />
        </div>

        <div v-if="fallbacks.length" class="border-b p-1">
            <ContextMenuItem
                v-for="archetype in fallbacks"
                :key="archetype.id"
                :disabled="disabled"
                class="flex items-center gap-2 italic"
                @select="onSelect(archetype.id)"
            >
                <Check v-if="archetype.id === currentArchetypeId" class="size-3.5 shrink-0" />
                <span v-else class="size-3.5 shrink-0" />
                <span class="flex-1 truncate">{{ archetype.name }}</span>
                <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide">System</span>
            </ContextMenuItem>
        </div>

        <div class="max-h-80 overflow-y-auto p-1">
            <ContextMenuItem
                v-for="archetype in regular"
                :key="archetype.id"
                :disabled="disabled"
                class="flex items-center gap-2"
                @select="onSelect(archetype.id)"
            >
                <Check v-if="archetype.id === currentArchetypeId" class="size-3.5 shrink-0" />
                <span v-else class="size-3.5 shrink-0" />
                <span class="flex-1 truncate">{{ archetype.name }}</span>
                <ManaSymbols :symbols="archetype.colorIdentity" />
            </ContextMenuItem>

            <p
                v-if="regular.length === 0"
                class="px-2 py-4 text-center text-xs text-muted-foreground"
            >
                No archetypes found.
            </p>
        </div>
    </div>
</template>
```

**Key details:**
- `@keydown.stop` on the Input prevents reka-ui's menu typeahead from intercepting letter keys.
- `@click.stop` on the Input prevents the menu from closing when the input is clicked.
- `Check` icon is always reserved as a 14px slot (placeholder span when not active) so rows don't shift width.
- `flex w-72` gives the submenu a fixed 288px width.
- `max-h-80` (320px) for scroll region.
- Fallbacks rendered separately, before the scrollable region — they stay visible when the regular list scrolls.
- Empty state only triggers for regular (since fallbacks are always visible if present).

- [ ] **Step 2: No isolated test**

Component is purely presentational + emit-based; verified end-to-end via Task 5 manual verification.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/matches/ArchetypePicker.vue
git commit -m "Add ArchetypePicker submenu component

Searchable archetype list with pinned fallbacks at top and a
scrollable filtered list of regular archetypes below."
```

---

## Task 4: Create `MatchRowContextMenu.vue` wrapper

**Files:**
- Create: `resources/js/components/matches/MatchRowContextMenu.vue`

Wraps a single match row's content in `ContextMenuTrigger`, renders the menu structure, and owns the inline archetype submission via Wayfinder + Inertia `useForm`.

- [ ] **Step 1: Write the component**

Create `resources/js/components/matches/MatchRowContextMenu.vue`:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import ArchetypePicker from '@/components/matches/ArchetypePicker.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';

const props = defineProps<{
    match: App.Data.Front.MatchData;
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const emit = defineEmits<{
    detect: [matchId: number];
    clear: [matchId: number];
    delete: [matchId: number];
    openNotes: [matchId: number, notes: string | null];
}>();

const submenuOpen = ref(false);

const currentArchetypeId = computed(() => props.match.opponentArchetypes?.[0]?.archetype?.id ?? null);

const hasArchetype = computed(() => currentArchetypeId.value !== null);

const setForm = useForm<{ archetype_id: number | null }>({
    archetype_id: null,
});

const selectArchetype = (archetypeId: number) => {
    setForm.archetype_id = archetypeId;
    setForm.submit(UpdateArchetypeController({ id: props.match.id }), {
        preserveScroll: true,
        onSuccess: () => {
            setForm.reset();
            submenuOpen.value = false;
        },
    });
};
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent class="w-56">
            <ContextMenuItem @select="emit('openNotes', match.id, match.notes ?? null)">
                {{ match.notes ? 'Edit notes' : 'Add notes' }}
            </ContextMenuItem>

            <ContextMenuSeparator />

            <ContextMenuLabel class="text-muted-foreground text-xs uppercase tracking-wide">
                Archetype
            </ContextMenuLabel>
            <ContextMenuItem @select="emit('detect', match.id)">Detect</ContextMenuItem>

            <ContextMenuSub v-model:open="submenuOpen">
                <ContextMenuSubTrigger>Set manually</ContextMenuSubTrigger>
                <ContextMenuSubContent class="p-0">
                    <ArchetypePicker
                        :archetypes="archetypes"
                        :format="match.format"
                        :current-archetype-id="currentArchetypeId"
                        :open="submenuOpen"
                        :disabled="setForm.processing"
                        @select="selectArchetype"
                    />
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuItem :disabled="!hasArchetype" @select="emit('clear', match.id)">
                Clear
            </ContextMenuItem>

            <ContextMenuSeparator />

            <ContextMenuItem
                class="text-destructive focus:text-destructive focus:bg-destructive/10"
                @select="emit('delete', match.id)"
            >
                Remove from stats
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>
```

**Key details:**
- `as-child` on `ContextMenuTrigger` lets the slotted `<TableRow>` receive the context-menu trigger props directly.
- Submission lives inside this component (uses Wayfinder + `useForm`) instead of bubbling up — keeps the data flow local.
- `submenuOpen` is `v-model:open`-bound so we can pass it to `ArchetypePicker` for focus management.
- `ContextMenuSubContent` gets `class="p-0"` because `ArchetypePicker` provides its own padding.
- "Clear" item uses `:disabled` rather than `v-if` to keep menu shape stable.
- Destructive item uses `text-destructive` plus focus-state styling.

- [ ] **Step 2: Commit**

```bash
git add resources/js/components/matches/MatchRowContextMenu.vue
git commit -m "Add MatchRowContextMenu wrapper component

Groups menu items into Notes / Archetype / Remove sections, with the
archetype picker rendered inline as a submenu instead of dialog."
```

---

## Task 5: Wire `MatchRowContextMenu` into `MatchesTable.vue`

**Files:**
- Modify: `resources/js/components/matches/MatchesTable.vue`

- [ ] **Step 1: Update imports**

In `resources/js/components/matches/MatchesTable.vue`, replace the context menu imports on line 3:

```typescript
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
```

with:

```typescript
import MatchRowContextMenu from '@/components/matches/MatchRowContextMenu.vue';
```

Also remove the now-unused `UpdateArchetypeController` import on line 12 (the new component owns archetype submission).

- [ ] **Step 2: Replace the inline `ContextMenu` block (lines 181-267) with `MatchRowContextMenu`**

The current structure is:

```vue
<ContextMenu>
    <ContextMenuTrigger asChild>
        <TableRow ...>...</TableRow>
    </ContextMenuTrigger>
    <ContextMenuContent>...</ContextMenuContent>
</ContextMenu>
```

Replace with:

```vue
<MatchRowContextMenu
    :match="match"
    :archetypes="archetypes ?? []"
    @detect="detectArchetype"
    @clear="clearArchetype"
    @delete="deleteMatch"
    @open-notes="(id, notes) => notesDialog?.openForMatch(id, notes)"
>
    <TableRow
        class="cursor-pointer"
        :data-state="selectedIds.includes(match.id) ? 'selected' : undefined"
        @click="router.visit(ShowController({ id: match.id }).url)"
    >
        <!-- all the existing TableCell contents from lines 188-253, unchanged -->
    </TableRow>
</MatchRowContextMenu>
```

Concretely, lines 181-267 of `MatchesTable.vue` become:

```vue
<MatchRowContextMenu
    :match="match"
    :archetypes="archetypes ?? []"
    @detect="detectArchetype"
    @clear="clearArchetype"
    @delete="deleteMatch"
    @open-notes="(id, notes) => notesDialog?.openForMatch(id, notes)"
>
    <TableRow
        class="cursor-pointer"
        :data-state="selectedIds.includes(match.id) ? 'selected' : undefined"
        @click="router.visit(ShowController({ id: match.id }).url)"
    >
        <TableCell @click.stop>
            <Checkbox
                :model-value="selectedIds.includes(match.id)"
                @update:model-value="(val) => toggleMatch(match.id, val)"
            />
        </TableCell>
        <TableCell>
            <ResultBadge :won="match.gamesWon > match.gamesLost" v-if="match.gamesWon !== match.gamesLost" :showText="true" />
        </TableCell>
        <TableCell v-if="showDeck">
            <span
                v-if="match.deck"
                class="text-primary hover:underline"
                @click.stop="router.visit(DeckDashboardController({ deck: match.deck.id }).url)"
            >
                {{ match.deck.name }}
            </span>
            <span v-else class="text-muted-foreground">Unknown</span>
        </TableCell>
        <TableCell class="font-medium">
            <span v-if="match.opponentName">{{ match.opponentName }}</span>
            <span v-else class="text-xs text-muted-foreground">&mdash;</span>
        </TableCell>
        <TableCell>
            <div class="flex items-center gap-1" v-if="match.opponentArchetypes?.[0]?.archetype">
                {{ match.opponentArchetypes[0].archetype.name }}
            </div>
            <span v-else-if="detectingMatchId === match.id" class="text-muted-foreground">
                <RefreshCw class="size-3.5 animate-spin" />
            </span>
            <span v-else class="text-muted-foreground">Unknown</span>
        </TableCell>
        <TableCell>
            <div v-if="match.opponentArchetypes?.[0]?.archetype">
                <ManaSymbols :symbols="match.opponentArchetypes[0].archetype.colorIdentity" />
            </div>
        </TableCell>
        <TableCell v-for="gameIdx in 3" :key="gameIdx" class="text-sm">
            <template v-if="match.gameResults?.[gameIdx - 1]">
                <span :class="match.gameResults[gameIdx - 1].result === 'W' ? 'text-success' : 'text-destructive'">
                    {{ match.gameResults[gameIdx - 1].result === 'W' ? 'Win' : 'Loss' }}
                </span>
                <span v-if="match.gameResults[gameIdx - 1].onPlay !== null" class="text-xs text-muted-foreground">
                    ({{ match.gameResults[gameIdx - 1].onPlay ? 'OTP' : 'OTD' }})
                </span>
            </template>
            <span v-else class="text-muted-foreground">&mdash;</span>
        </TableCell>
        <TableCell>{{ match.matchTime }}</TableCell>
        <TableCell>{{ match.startedAtFormatted }}</TableCell>
        <TableCell>
            <TooltipProvider v-if="match.notes">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <NotepadText :size="14" class="text-muted-foreground" />
                    </TooltipTrigger>
                    <TooltipContent side="left" class="max-w-xs">
                        <p class="text-xs whitespace-pre-wrap">{{ match.notes }}</p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </TableCell>
    </TableRow>
</MatchRowContextMenu>
```

- [ ] **Step 3: Verify lints/types**

```bash
npm run lint
```

Expected: pass (or only pre-existing warnings).

```bash
npx tsc --noEmit
```

Expected: pass.

- [ ] **Step 4: Manual verification — golden path**

Run the app (`composer run dev` or whatever the user has running), then on a match list:

1. Right-click a match row → menu opens.
2. Verify menu order: Add notes → separator → ARCHETYPE label → Detect → Set manually ▸ → Clear → separator → Remove from stats.
3. Hover "Set manually" → submenu opens to the side.
4. Verify submenu shows search input → fallbacks (Homebrew, Rogue) → divider → scrollable regular archetypes.
5. Type "burn" → fallbacks remain, regular list filters.
6. Clear search → all regular return.
7. Click an archetype → archetype assigned to row, menu closes, row updates.
8. Right-click same row → "Clear" enabled. Click → archetype clears.
9. Right-click row with no archetype → "Clear" disabled.
10. Click "Remove from stats" → row removes (existing behavior).
11. Click "Add notes" → existing notes dialog opens.
12. Click "Detect" → existing detection runs, spinner shows.

- [ ] **Step 5: Manual verification — edge cases**

1. Match with archetype already set → submenu shows ✓ next to that archetype.
2. Right-click row near bottom of viewport → menu/submenu flip up.
3. Bulk select 2+ rows → toolbar "Set archetype" still opens dialog (not picker).
4. Search yields no results → "No archetypes found." in scroll region; fallbacks still visible.
5. Format-mismatched archetypes (legacy archetypes on a modern deck) hidden from regular list, fallbacks still visible.

- [ ] **Step 6: Run pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/components/matches/MatchesTable.vue
git commit -m "Wire MatchRowContextMenu into MatchesTable

Replaces flat inline context menu with grouped menu and inline
archetype picker submenu. Bulk dialog remains for multi-row selection."
```

---

## Task 6: Final pass

- [ ] **Step 1: Search for now-dead code**

```bash
grep -rn "openForMatch" resources/js/
```

Expected: only `SetArchetypeDialog.vue` (bulk path) and `MatchNotesDialog.vue` reference `openForMatch` / `openForMatches`. The single-match dialog open path from MatchesTable should be gone.

- [ ] **Step 2: Verify no unused imports in MatchesTable.vue**

Open `resources/js/components/matches/MatchesTable.vue` and confirm:
- `ContextMenu`, `ContextMenuContent`, `ContextMenuItem`, `ContextMenuTrigger` imports removed.
- `UpdateArchetypeController` import removed (now lives in `MatchRowContextMenu`).
- `MatchRowContextMenu` import added.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 4: Final commit (if any cleanup)**

```bash
git add -u
git commit -m "Remove unused imports after context menu refactor" || echo "nothing to clean"
```

---

## Self-review notes

- **Spec coverage:**
  - Top-level menu structure (notes / Archetype label + Detect/Set manually/Clear / Remove) → Task 4 component template.
  - Submenu layout (sticky search, pinned fallbacks, border, scrollable filtered list) → Task 3 component template.
  - Search filters regular only → Task 1 composable + verified in Task 2 dialog refactor.
  - ✓ on currently-set archetype → Task 3 `currentArchetypeId` prop wiring, `Check` icon.
  - "Clear" disabled when no archetype → Task 4 `:disabled="!hasArchetype"`.
  - Bulk dialog untouched UX-wise → Task 2 only swaps internal logic.
  - Mana symbols on rows → Task 3 `<ManaSymbols :symbols="archetype.colorIdentity" />`.
  - No match counts → confirmed (rows show name + ✓ slot + mana only).
  - Format filter → Task 1 composable handles via `formatMap`.
- **Placeholder scan:** none.
- **Type consistency:** `MatchRowContextMenu` props match what `MatchesTable.vue` passes; `ArchetypePicker` props match what `MatchRowContextMenu` passes; `useArchetypeSplit` signature consistent.
- **Open question for user (raised in Task 2 Step 2):** the existing dialog used to filter fallbacks by search too. The new composable does not. Confirm with user before merging. If they want fallbacks searchable in the dialog only, add a `searchFallbacks: boolean` flag to the composable and default it to `false`; dialog passes `true`.
