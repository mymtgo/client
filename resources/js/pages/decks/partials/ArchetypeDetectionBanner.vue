<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useToast } from '@/composables/useToast';
import TriggerArchetypeDetectionController from '@/actions/App/Http/Controllers/Decks/TriggerArchetypeDetectionController';
import { router } from '@inertiajs/vue3';
import { Loader2, Sparkles } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    deckId: number;
    filterArchetype: string;
    pendingCount: number;
    deletedAt?: string | null;
}>();

const { add: toast } = useToast();
const submitting = ref(false);
const pollHandle = ref<number | null>(null);

const hasPending = computed(() => props.pendingCount > 0);
const isReadonly = computed(() => !!props.deletedAt);
const buttonDisabled = computed(() => submitting.value || hasPending.value);

function trigger() {
    submitting.value = true;
    router.post(
        TriggerArchetypeDetectionController.url({ deck: props.deckId }),
        { filter_archetype: props.filterArchetype },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = (page.props.flash as Record<string, unknown> | undefined) ?? {};
                const queued = flash.archetypeDetectionQueued;
                if (typeof queued === 'number') {
                    toast({
                        type: 'success',
                        title: 'Detection queued',
                        message: `Queued archetype detection for ${queued} match${queued === 1 ? '' : 'es'}`,
                    });
                }
            },
            onError: () => {
                toast({
                    type: 'error',
                    title: 'Failed',
                    message: 'Could not queue archetype detection.',
                });
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function startPolling() {
    if (pollHandle.value !== null) return;
    pollHandle.value = window.setInterval(() => {
        router.reload({
            only: ['pendingArchetypeCount', 'matches', 'archetypes', 'unknownArchetypeCount'],
            preserveScroll: true,
        });
    }, 3000);
}

function stopPolling() {
    if (pollHandle.value !== null) {
        clearInterval(pollHandle.value);
        pollHandle.value = null;
    }
}

watch(
    () => props.pendingCount,
    (count) => {
        if (count > 0) startPolling();
        else stopPolling();
    },
    { immediate: true },
);

onUnmounted(stopPolling);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border/60 bg-muted/40 px-3 py-2">
        <div class="flex items-center gap-2 text-xs">
            <Sparkles class="size-4 text-muted-foreground" />
            <span>Re-run archetype detection for matches in this filter.</span>
            <span
                v-if="hasPending"
                class="inline-flex items-center gap-1 rounded-full bg-background px-2 py-0.5 text-[11px] text-muted-foreground"
            >
                <Loader2 class="size-3 animate-spin" />
                {{ pendingCount }} in queue
            </span>
        </div>
        <Button
            size="sm"
            :disabled="buttonDisabled || isReadonly"
            :title="isReadonly ? 'Deck deleted on MTGO — read-only' : undefined"
            @click="trigger"
        >
            <Loader2 v-if="submitting" class="size-3 animate-spin" />
            Detect archetypes
        </Button>
    </div>
</template>
