<script setup lang="ts">
import DeckListing from '@/components/AppNavigation/DeckListing.vue';
import { Button } from '@/components/ui/button';
import { Link, usePage } from '@inertiajs/vue3';
import { PhStack, PhStackSimple, PhTrophy } from '@phosphor-icons/vue';
import { ref } from 'vue';

const page = usePage<{
    decks: any;
}>();

const showAllDecks = ref<boolean>(true);
</script>

<template>
    <div>
        <Button>Hello</Button>
        <nav class="grid grid-cols-2 gap-2 p-4 text-sm [&>a]:flex">
            <Link href="#" class="items-center justify-between gap-2 rounded-md border border-slate-900 px-4 py-2">
                <span class="block">Limited</span>
                <span>
                    <PhStackSimple class="text-purple-500" :size="16" />
                </span>
            </Link>
            <Link href="#" class="items-center justify-between gap-2 rounded-md border border-slate-900 px-4 py-2">
                <span class="block">Leagues</span>
                <span>
                    <PhTrophy class="text-yellow-500" :size="16" />
                </span>
            </Link>
        </nav>
        <nav class="border-t border-slate-900 p-2 px-4 text-sm">
            <header class="flex items-center gap-2 text-base font-semibold">
                <span><PhStack weight="duotone" :size="16" class="text-blue-500" /></span>
                <span class="text-slate-100">Decks</span>
            </header>
            <div class="mt-2">
                <ul class="text-base-content space-y-2">
                    <li v-for="(decks, format) in page.props.decks" :key="`format_${format}`">
                        <DeckListing :format="format" :decks="decks" />
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</template>

<style scoped></style>
