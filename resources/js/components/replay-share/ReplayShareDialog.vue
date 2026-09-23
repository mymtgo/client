<script setup lang="ts">
import RevokeReplayShareController from '@/actions/App/Http/Controllers/Games/RevokeReplayShareController';
import ShareReplayController from '@/actions/App/Http/Controllers/Games/ShareReplayController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/vue3';
import { Check, Copy, Lock } from 'lucide-vue-next';
import { computed, shallowRef, watch } from 'vue';
import type { ReplayShareState } from './types';

const props = defineProps<{
    open: boolean;
    gameId: number;
    share: ReplayShareState;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const page = usePage();
const busy = shallowRef(false);
const copied = shallowRef(false);

const error = computed(() => (page.props.errors as Record<string, string> | undefined)?.share ?? null);

/**
 * The lock is informational only: the server decides, so a stale local tier
 * never stops a real supporter from sharing.
 */
const showSupporterNote = computed(() => props.share.linked && !props.share.supporter && !props.share.url);

watch(
    () => props.open,
    (open) => {
        if (open) {
            copied.value = false;
        }
    },
);

const visitOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['share', 'errors'],
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
};

function createLink() {
    router.post(ShareReplayController.url(props.gameId), {}, visitOptions);
}

function switchOff() {
    router.delete(RevokeReplayShareController.url(props.gameId), visitOptions);
}

async function copy() {
    if (!props.share.url) {
        return;
    }

    await navigator.clipboard.writeText(props.share.url);
    copied.value = true;
}
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => emit('update:open', value)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Share this match</DialogTitle>
                <DialogDescription>
                    Anyone with the link can watch every game of this match, starting from this one. Your opponent's name is hidden.
                </DialogDescription>
            </DialogHeader>

            <p v-if="!share.linked" class="text-sm text-muted-foreground">Sign in from Settings to share replays.</p>

            <p v-if="showSupporterNote" class="flex items-center gap-2 text-sm text-muted-foreground">
                <Lock :size="14" />
                Sharing replays is a supporter feature.
            </p>

            <div v-if="share.url" class="flex items-center gap-2">
                <input
                    readonly
                    :value="share.url"
                    aria-label="Replay link"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-3 font-mono text-xs"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button variant="secondary" size="sm" class="cursor-pointer" @click="copy">
                    <Check v-if="copied" />
                    <Copy v-else />
                    {{ copied ? 'Copied' : 'Copy link' }}
                </Button>
            </div>

            <p v-if="error" class="text-sm text-[#e5484d]">{{ error }}</p>

            <p v-if="share.url" class="text-xs text-muted-foreground">Played more games since sharing? Update the link to add them.</p>

            <DialogFooter>
                <Button v-if="share.url" variant="ghost" size="sm" class="cursor-pointer" :disabled="busy" @click="switchOff">Switch off link</Button>
                <Button v-if="share.linked" :variant="share.url ? 'secondary' : 'default'" size="sm" class="cursor-pointer" :disabled="busy" @click="createLink">
                    {{ busy ? 'Uploading...' : share.url ? 'Update link' : 'Create link' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
