<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    showDrawOdds: boolean;
    showSideboard: boolean;
    showReveals: boolean;
    /** Collapsed: only the bar shows, so the player can see what the overlay sits over. */
    collapsed: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    'update:collapsed': [value: boolean];
}>();

const sections = computed(() =>
    [
        { value: 'draw-odds', label: 'Draw odds', enabled: props.showDrawOdds },
        { value: 'reveals', label: 'Revealed', enabled: props.showReveals },
        { value: 'sideboard', label: 'Sideboarding', enabled: props.showSideboard },
    ].filter((section) => section.enabled),
);

// Picking a tab while collapsed means the player wants to read it.
function expand() {
    if (props.collapsed) emit('update:collapsed', false);
}

const toggleLabel = computed(() => (props.collapsed ? 'Expand overlay' : 'Collapse overlay'));
</script>

<template>
    <!-- One section enabled (or none): no tabs to choose from, just its name and the collapse toggle. -->
    <div v-if="sections.length <= 1" class="flex min-h-0 flex-col" :class="!collapsed && 'flex-1'">
        <div v-if="sections[0]" data-overlay-bar class="flex shrink-0 items-center gap-2 border-b border-border py-1 pr-1 pl-3">
            <span class="flex-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">{{ sections[0].label }}</span>
            <button
                type="button"
                class="flex size-7 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                :aria-label="toggleLabel"
                :title="toggleLabel"
                :aria-expanded="!collapsed"
                style="-webkit-app-region: no-drag"
                @click="emit('update:collapsed', !collapsed)"
            >
                <ChevronDown v-if="collapsed" class="size-4" />
                <ChevronUp v-else class="size-4" />
            </button>
        </div>
        <div v-if="!collapsed" class="min-h-0 flex-1 overflow-y-auto">
            <slot v-if="sections[0]" :name="sections[0].value" />
            <p v-else class="p-3 text-center text-xs text-muted-foreground">Enable draw odds, revealed cards, or the sideboard guide in Settings.</p>
        </div>
    </div>

    <Tabs
        v-else
        :model-value="props.modelValue"
        class="flex min-h-0 flex-col gap-0"
        :class="!collapsed && 'flex-1'"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <div data-overlay-bar class="flex shrink-0 items-center border-b border-border bg-black/50 pr-1">
            <TabsList class="min-w-0 flex-1 rounded-none border-0 bg-transparent" style="-webkit-app-region: no-drag">
                <TabsTrigger
                    v-for="section in sections"
                    :key="section.value"
                    :value="section.value"
                    :class="['flex-1 text-xs', collapsed && 'data-[state=active]:bg-transparent data-[state=active]:shadow-none']"
                    style="-webkit-app-region: no-drag"
                    @click="expand"
                >
                    {{ section.label }}
                </TabsTrigger>
            </TabsList>
            <button
                type="button"
                class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-white/5 hover:text-foreground"
                :aria-label="toggleLabel"
                :title="toggleLabel"
                :aria-expanded="!collapsed"
                style="-webkit-app-region: no-drag"
                @click="emit('update:collapsed', !collapsed)"
            >
                <ChevronDown v-if="collapsed" class="size-4" />
                <ChevronUp v-else class="size-4" />
            </button>
        </div>

        <template v-if="!collapsed">
            <TabsContent v-for="section in sections" :key="section.value" :value="section.value" class="min-h-0 flex-1 overflow-y-auto">
                <slot :name="section.value" />
            </TabsContent>
        </template>
    </Tabs>
</template>
