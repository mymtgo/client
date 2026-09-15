<script setup lang="ts">
import AdvancedController from '@/actions/App/Http/Controllers/Settings/Pages/AdvancedController';
import GeneralController from '@/actions/App/Http/Controllers/Settings/Pages/GeneralController';
import OverlaysController from '@/actions/App/Http/Controllers/Settings/Pages/OverlaysController';
import PrivacyController from '@/actions/App/Http/Controllers/Settings/Pages/PrivacyController';
import StorageController from '@/actions/App/Http/Controllers/Settings/Pages/StorageController';
import type { SettingsCurrentPage } from '@/types/settings';
import { Link } from '@inertiajs/vue3';
import { HardDrive, Layers, ShieldCheck, SlidersHorizontal, Wrench, type LucideIcon } from 'lucide-vue-next';

defineProps<{
    currentPage: SettingsCurrentPage;
}>();

type NavItem = {
    key: SettingsCurrentPage;
    label: string;
    icon: LucideIcon;
    href: string;
};

const items: NavItem[] = [
    { key: 'general', label: 'General', icon: SlidersHorizontal, href: GeneralController.url() },
    { key: 'overlays', label: 'Overlays', icon: Layers, href: OverlaysController.url() },
    { key: 'storage', label: 'Storage', icon: HardDrive, href: StorageController.url() },
    { key: 'privacy', label: 'Data & Privacy', icon: ShieldCheck, href: PrivacyController.url() },
    { key: 'advanced', label: 'Advanced', icon: Wrench, href: AdvancedController.url() },
];
</script>

<template>
    <div class="flex h-full flex-col border-r border-black/80 bg-muted/20">
        <div class="border-b border-black/80 px-3 py-3">
            <h2 class="text-sm font-semibold text-foreground">Settings</h2>
            <p class="text-xs text-muted-foreground">Configure how mymtgo tracks and displays your games.</p>
        </div>
        <nav class="flex flex-1 flex-col gap-0.5 px-2 py-3">
            <Link
                v-for="item in items"
                :key="item.key"
                :href="item.href"
                prefetch="hover"
                cache-for="10s"
                class="flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    currentPage === item.key
                        ? 'border border-black/50 bg-black/10 text-foreground shadow-inner shadow-black/50 outline outline-white/5'
                        : 'border border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                "
            >
                <component
                    :is="item.icon"
                    class="size-4 shrink-0 transition-[color,filter] duration-150"
                    :class="{ 'nav-icon-active': currentPage === item.key }"
                />
                <span>{{ item.label }}</span>
            </Link>
        </nav>
    </div>
</template>

<style scoped>
.nav-icon-active {
    color: #38bdf8;
    filter: drop-shadow(0 0 4px rgba(56, 189, 248, 0.7)) drop-shadow(0 0 8px rgba(56, 189, 248, 0.35));
}
</style>
