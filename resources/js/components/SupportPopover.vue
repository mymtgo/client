<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Ticket } from 'lucide-vue-next';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

const page = usePage<{
    donation: { showModal: boolean; tixHandle: string | null };
}>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button type="button" class="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                <Ticket class="size-3.5" />
                <span>Support development</span>
            </button>
        </PopoverTrigger>

        <PopoverContent side="top" align="end" class="w-80 space-y-3">
            <div class="space-y-1">
                <p class="text-sm font-semibold">Built by one person who plays MTGO too</p>
                <p class="text-xs text-muted-foreground">
                    The tracker's free and always will be. If it's earned its keep, a few tix in-game keep me building. No pressure.
                </p>
            </div>

            <div
                v-if="page.props.donation?.tixHandle"
                class="rounded-md border border-border/60 bg-muted/40 px-3 py-2"
            >
                <p class="text-xs text-muted-foreground">Send tix in-game to</p>
                <p class="mt-0.5 font-mono text-sm font-semibold text-foreground">
                    {{ page.props.donation.tixHandle }}
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
