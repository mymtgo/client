<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Settings } from 'lucide-vue-next';
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';
</script>

<template>
    <header class="flex h-12 shrink-0 items-center justify-between bg-sidebar px-4 text-sidebar-foreground">
        <Link :href="DashboardController.url()" class="text-base font-semibold tracking-tight">
            mymtgo
        </Link>

        <Link
            :href="SettingsIndexController.url()"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
        >
            <Settings class="size-4" />
        </Link>
    </header>
</template>
