<script setup lang="ts">
import { ref, toRef, watch, nextTick } from 'vue';
import { ContextMenuItem } from '@/components/ui/context-menu';
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
const searchInput = ref<InstanceType<typeof Input> | null>(null);

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), toRef(props, 'format'), search);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            search.value = '';
            nextTick(() => (searchInput.value?.$el as HTMLInputElement | undefined)?.focus());
        }
    },
);

const onSelect = (archetypeId: number) => {
    if (props.disabled) {
        return;
    }
    emit('select', archetypeId);
};

const onSearchKeydown = (event: KeyboardEvent) => {
    const navKeys = ['ArrowDown', 'ArrowUp', 'Escape', 'Enter', 'Tab'];
    if (!navKeys.includes(event.key)) {
        event.stopPropagation();
    }
};
</script>

<template>
    <div class="flex w-72 flex-col">
        <div class="border-b p-1">
            <Input
                ref="searchInput"
                v-model="search"
                placeholder="Search archetypes..."
                aria-label="Search archetypes"
                class="h-8"
                @keydown="onSearchKeydown"
                @click.stop
            />
        </div>

        <div v-if="fallbacks.length" class="grid grid-cols-2 gap-1 border-b p-1">
            <ContextMenuItem
                v-for="archetype in fallbacks"
                :key="archetype.id"
                :disabled="disabled"
                class="flex items-center justify-center gap-2 italic"
                @select="onSelect(archetype.id)"
            >
                <Check v-if="archetype.id === currentArchetypeId" class="size-3.5 shrink-0" />
                <span class="truncate">{{ archetype.name }}</span>
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
