<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';
import { cn } from '@/lib/utils';
import { computed, nextTick, onMounted, ref, toRef } from 'vue';

/**
 * The one archetype search list. Settings renders it inline, the match dialog
 * and the deck listing render it inside their own containers. Plain ghost
 * buttons rather than a Combobox so the three usages look identical and the
 * inline case needs no popover chrome.
 */
const props = withDefaults(
    defineProps<{
        archetypes: App.Data.Front.ArchetypeData[] | undefined;
        format?: string | null;
        showFallbacks?: boolean;
        disabled?: boolean;
        autofocus?: boolean;
        listClass?: string;
        /** Label each row with its format. For lists that span formats, where same-named archetypes would otherwise be indistinguishable. */
        showFormat?: boolean;
    }>(),
    { format: null, showFallbacks: false, disabled: false, autofocus: false, listClass: '', showFormat: false },
);

const emit = defineEmits<{ select: [archetypeId: number] }>();

const search = ref('');
const searchInput = ref<{ $el: HTMLInputElement } | null>(null);

const loaded = computed(() => props.archetypes ?? []);
const { fallbacks, regular } = useArchetypeSplit(loaded, toRef(props, 'format'), search);
const visibleFallbacks = computed(() => (props.showFallbacks ? fallbacks.value : []));

function formatLabel(format: string | null): string {
    if (!format) return '';
    return format.charAt(0).toUpperCase() + format.slice(1);
}

onMounted(() => {
    if (props.autofocus) {
        nextTick(() => searchInput.value?.$el?.focus());
    }
});

function choose(id: number) {
    emit('select', id);
    search.value = '';
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <Input ref="searchInput" v-model="search" placeholder="Search archetypes..." :disabled="disabled" />

        <div :class="cn('flex max-h-60 flex-col gap-0.5 overflow-y-auto rounded-md border border-border p-1', listClass)">
            <div v-if="archetypes === undefined" class="flex items-center justify-center py-6">
                <Spinner class="size-4" />
            </div>

            <template v-else>
                <template v-if="visibleFallbacks.length">
                    <Button
                        v-for="archetype in visibleFallbacks"
                        :key="archetype.id"
                        variant="ghost"
                        class="w-full justify-between italic text-muted-foreground"
                        :disabled="disabled"
                        @click="choose(archetype.id)"
                    >
                        <span class="flex-1 text-left">{{ archetype.name }}</span>
                        <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] tracking-wide uppercase">System</span>
                    </Button>
                    <div class="my-1 border-t border-border" />
                </template>

                <Button
                    v-for="archetype in regular"
                    :key="archetype.id"
                    variant="ghost"
                    class="w-full justify-between"
                    :disabled="disabled"
                    @click="choose(archetype.id)"
                >
                    <span class="flex-1 truncate text-left">{{ archetype.name }}</span>
                    <span v-if="showFormat && archetype.format" class="shrink-0 text-[10px] tracking-wide text-muted-foreground uppercase">{{ formatLabel(archetype.format) }}</span>
                    <ManaSymbols v-if="archetype.colorIdentity" :symbols="archetype.colorIdentity" />
                </Button>

                <p v-if="visibleFallbacks.length === 0 && regular.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                    No archetypes found.
                </p>
            </template>
        </div>
    </div>
</template>
