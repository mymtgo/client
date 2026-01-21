<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage<{
    decks: App.Data.Front.DeckData[];
}>();
</script>

<template>
    <div>
        <SidebarGroup class="group-data-[collapsible=icon]:hidden" v-for="(decks, format) in page.props.decks" :key="`format_${format}`">
            <SidebarGroupLabel>
                {{ format }}
            </SidebarGroupLabel>
            <SidebarMenu>
                <SidebarMenuItem v-for="deck in decks" :key="deck.name">
                    <SidebarMenuButton as-child>
                        <Link :href="ShowController(deck.id).url">
                            <span>{{ deck.name }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroup>
    </div>
</template>
