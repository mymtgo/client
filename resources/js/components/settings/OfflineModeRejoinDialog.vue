<script setup lang="ts">
/**
 * Confirms leaving offline mode.
 *
 * Leaving starts a cooldown before offline mode can be switched back on, so
 * this exists to state that before the fact rather than after. Deliberately
 * only shown on the way OUT, since turning privacy back ON should never need
 * confirming.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    submitting?: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Come back online?</DialogTitle>
                <DialogDescription>
                    Your matches will start being shared again, and community card stats,
                    opponent scouting and archetype updates come back with them.
                </DialogDescription>
            </DialogHeader>

            <p class="text-sm text-warning">
                You won't be able to turn offline mode back on until tomorrow.
            </p>

            <DialogFooter>
                <Button variant="outline" :disabled="props.submitting" @click="emit('update:open', false)">
                    Stay offline
                </Button>
                <Button :disabled="props.submitting" @click="emit('confirm')">
                    Come back online
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
