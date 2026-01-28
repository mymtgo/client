<script setup lang="ts">
import NavDecks from '@/components/NavDecks.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Button } from '@/components/ui/button';
import { Settings2Icon as CogIcon } from 'lucide-vue-next';
</script>

<template>
    <Sidebar collapsible="offcanvas">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child class="data-[slot=sidebar-menu-button]:p-1.5!">
                        <a href="#">
                            <span class="text-base font-semibold">mymtgo</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>
        <SidebarContent class="no-scrollbar">
            <NavDecks />
        </SidebarContent>
        <SidebarFooter>
            <div>
                <Button variant="outline">
                    <CogIcon />
                    Settings</Button>
            </div>
        </SidebarFooter>
    </Sidebar>
</template>
