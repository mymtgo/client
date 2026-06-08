<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import MarkDonationPromptSeenController from '@/actions/App/Http/Controllers/Support/MarkDonationPromptSeenController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import eventTicketsArt from '../../images/event-tickets.png';

const page = usePage<{
    donation: { showModal: boolean; tixHandle: string | null };
}>();

const open = ref(false);
const dismissing = ref(false);

// The server only sets showModal once enough games are tracked, the prompt is
// unseen, and a tix handle is configured — so opening is purely reactive here.
watch(
    () => page.props.donation?.showModal,
    (show) => {
        if (show) {
            open.value = true;
        }
    },
    { immediate: true },
);

function dismiss(): void {
    open.value = false;

    if (dismissing.value) {
        return;
    }

    dismissing.value = true;

    // Persist server-side so the takeover never fires again. preserveState keeps
    // the current page intact; we only care about the side effect.
    router.post(
        MarkDonationPromptSeenController.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                dismissing.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open" @update:open="(value) => !value && dismiss()">
        <DialogContent class="max-w-md overflow-hidden">
            <div class="flex flex-col items-center gap-5 pt-2 text-center">
                <img
                    :src="eventTicketsArt"
                    alt="MTGO Event Tickets"
                    width="180"
                    height="130"
                    class="drop-shadow-[0_8px_24px_rgba(180,60,20,0.35)] select-none"
                    draggable="false"
                />

                <DialogHeader class="items-center gap-2 text-center sm:text-center">
                    <DialogTitle class="text-xl">Still tracking? Nice.</DialogTitle>
                    <DialogDescription class="max-w-sm text-balance">
                        MTGO Tracker is free and always will be. If it's earned a spot in your routine, a few tix in-game keeps it getting
                        better. No pressure.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="page.props.donation?.tixHandle"
                    class="w-full rounded-lg border border-border/60 bg-muted/40 px-4 py-3 text-sm"
                >
                    <p class="text-muted-foreground">Send tix in-game to</p>
                    <p class="mt-0.5 font-mono text-base font-semibold tracking-wide text-foreground">
                        {{ page.props.donation.tixHandle }}
                    </p>
                </div>
            </div>

            <DialogFooter class="sm:justify-center">
                <Button class="min-w-32" @click="dismiss">Got it</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
