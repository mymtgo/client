<script setup lang="ts">
import CardHoverPreview from '@/components/cards/CardHoverPreview.vue';
import { Button } from '@/components/ui/button';
import { Minus, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One card in the guide editor: a 0..max stepper for planned copies followed by
 * the ceiling it counts against, the art crop and name, then whatever stats the
 * caller slots in. Stale rows (card no longer in the zone this row plans
 * against) cannot be incremented, only cleared, and show a badge in place of
 * the ceiling.
 *
 * Only the art and name preview the full card. The stepper is the thing being
 * clicked, so hovering it must not throw an image over the neighbouring column.
 */
const props = defineProps<{
    name: string;
    artCrop: string | null;
    /** Full card image for the hover preview; no image means no preview. */
    image: string | null;
    owned: number;
    planned: number;
    stale?: boolean;
    /** Which zone this row plans against; drives the stale badge copy. */
    direction: 'in' | 'out';
}>();

const emit = defineEmits<{ change: [value: number] }>();

/** Staleness is zone-based: an "in" entry is stale when the sideboard no longer holds the card, an "out" entry when the maindeck does not. */
const staleLabel = computed(() => (props.direction === 'in' ? 'Not in sideboard' : 'Not in maindeck'));

function step(delta: number): void {
    const next = Math.max(0, Math.min(props.stale ? props.planned : props.owned, props.planned + delta));
    if (next !== props.planned) emit('change', next);
}
</script>

<template>
    <div class="flex items-center gap-2 py-1.5 text-sm" :class="{ 'opacity-60': planned === 0 && !stale }">
        <div class="flex shrink-0 items-center rounded-md border border-border">
            <Button variant="ghost" size="icon" class="size-7 rounded-r-none" :disabled="planned === 0" aria-label="Fewer" @click="step(-1)">
                <Minus class="size-3" />
            </Button>
            <span class="w-7 text-center font-semibold tabular-nums" :class="planned > 0 ? 'text-foreground' : 'text-muted-foreground'">
                {{ planned }}
            </span>
            <Button
                variant="ghost"
                size="icon"
                class="size-7 rounded-l-none"
                :disabled="stale || planned >= owned"
                aria-label="More"
                @click="step(1)"
            >
                <Plus class="size-3" />
            </Button>
        </div>
        <!-- Fixed width so a stale row, which has no ceiling to show, keeps the art and name aligned with its neighbours. -->
        <span class="w-6 shrink-0 text-xs text-muted-foreground tabular-nums">
            <template v-if="!stale">/ {{ owned }}</template>
        </span>
        <CardHoverPreview :image="image" :name="name">
            <span class="flex min-w-0 grow items-center gap-2">
                <span class="h-7 w-7 shrink-0 overflow-hidden rounded bg-black/20">
                    <img v-if="artCrop" :src="artCrop" :alt="name" class="h-full w-full object-cover" />
                </span>
                <span class="min-w-0 truncate">
                    {{ name }}
                    <span
                        v-if="stale"
                        class="ml-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-amber-300 uppercase"
                    >
                        {{ staleLabel }}
                    </span>
                </span>
            </span>
        </CardHoverPreview>
        <slot />
    </div>
</template>
