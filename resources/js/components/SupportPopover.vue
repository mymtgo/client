<script setup lang="ts">
import OpenKofiController from '@/actions/App/Http/Controllers/Support/OpenKofiController';
import KofiIcon from '@/components/icons/KofiIcon.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ref } from 'vue';

const KOFI_URL = 'https://ko-fi.com/mymtgo';
const opening = ref(false);
const open = ref(false);

async function openKofi(): Promise<void> {
    opening.value = true;
    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        await fetch(OpenKofiController.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
        });
    } finally {
        opening.value = false;
        open.value = false;
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button type="button" class="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                <KofiIcon class="size-3.5" />
                <span>Support development</span>
            </button>
        </PopoverTrigger>

        <PopoverContent side="top" align="end" class="w-80 space-y-3">
            <div class="space-y-1">
                <p class="text-sm font-semibold">Enjoying the tracker?</p>
                <p class="text-xs text-muted-foreground">
                    MTGO Tracker is free and always will be. If it has saved you time or made your matches more fun, a small tip on Ko-fi helps fund
                    continued development. Totally optional, but really appreciated.
                </p>
            </div>

            <Button
                type="button"
                class="w-full bg-[#FF5A16] text-white hover:bg-[#e64f10]"
                :disabled="opening"
                @click="openKofi"
            >
                <KofiIcon class="size-4" />
                Support on Ko-fi
            </Button>
            <span class="block text-center text-xs text-neutral-400">{{ KOFI_URL }}</span>
        </PopoverContent>
    </Popover>
</template>
