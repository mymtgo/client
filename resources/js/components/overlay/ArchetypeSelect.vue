<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';
import { Check, ChevronDown } from 'lucide-vue-next';
import { computed, ref, toRef } from 'vue';

const props = defineProps<{
    archetypes: App.Data.Front.ArchetypeData[];
    format: string | null;
    currentArchetypeId: number | null;
    currentArchetypeName: string | null;
    /** Short label for where the archetype came from, e.g. "guess". */
    sourceLabel?: string | null;
    disabled?: boolean;
}>();

const emit = defineEmits<{ select: [archetypeId: number] }>();

const open = ref(false);
const search = ref('');

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), toRef(props, 'format'), search);

/**
 * The whole list is deferred by the controller and defaulted to `[]` on the
 * page, so an empty prop means the fetch is still in flight — distinct from
 * "this format has no archetypes", which still yields the fallbacks.
 */
const loading = computed(() => props.archetypes.length === 0);

/** Nothing to show, with the list already loaded — including no fallbacks. */
const empty = computed(() => !loading.value && regular.value.length === 0 && fallbacks.value.length === 0);

function choose(archetypeId: number): void {
    open.value = false;
    search.value = '';
    emit('select', archetypeId);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger
            :disabled="props.disabled"
            class="flex w-full items-center justify-between gap-2 rounded-md border border-border bg-background px-2 py-1.5 text-left text-sm font-semibold disabled:opacity-50"
            style="-webkit-app-region: no-drag"
        >
            <span class="truncate">{{ props.currentArchetypeName ?? 'Unknown archetype' }}</span>
            <span class="flex shrink-0 items-center gap-1.5">
                <span
                    v-if="props.sourceLabel"
                    class="rounded bg-muted px-1 py-px text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                    >{{ props.sourceLabel }}</span
                >
                <ChevronDown class="size-4 text-muted-foreground" />
            </span>
        </PopoverTrigger>

        <PopoverContent
            align="start"
            side="bottom"
            class="flex w-[var(--reka-popover-trigger-width)] flex-col gap-1 p-1"
            style="-webkit-app-region: no-drag"
        >
            <Input v-model="search" placeholder="Search archetypes…" class="h-7 text-xs" />

            <div class="max-h-56 overflow-y-auto">
                <button
                    v-for="archetype in regular"
                    :key="archetype.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-left text-xs hover:bg-accent"
                    @click="choose(archetype.id)"
                >
                    <span class="flex min-w-0 items-center gap-1">
                        <Check v-if="archetype.id === props.currentArchetypeId" class="size-3 shrink-0" />
                        <span class="truncate">{{ archetype.name }}</span>
                    </span>
                    <ManaSymbols v-if="archetype.colorIdentity" :symbols="archetype.colorIdentity" class="shrink-0" />
                </button>

                <button
                    v-for="archetype in fallbacks"
                    :key="archetype.id"
                    type="button"
                    class="flex w-full items-center gap-1 rounded px-2 py-1 text-left text-xs text-muted-foreground hover:bg-accent"
                    @click="choose(archetype.id)"
                >
                    <Check v-if="archetype.id === props.currentArchetypeId" class="size-3 shrink-0" />
                    <span class="truncate">{{ archetype.name }}</span>
                </button>

                <div v-if="loading" class="flex flex-col gap-1 p-1" aria-label="Loading archetypes">
                    <Skeleton v-for="row in 5" :key="row" class="h-5 w-full" />
                </div>

                <p v-else-if="empty" class="px-2 py-4 text-center text-xs text-muted-foreground">No archetypes found.</p>
            </div>
        </PopoverContent>
    </Popover>
</template>
