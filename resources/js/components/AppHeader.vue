<script setup lang="ts">
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';
import SwitchAccountController from '@/actions/App/Http/Controllers/Settings/SwitchAccountController';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Settings } from 'lucide-vue-next';

const page = usePage<{
    activeAccount: string | null;
    accounts: Array<{ id: number; username: string; active: boolean }>;
}>();

function switchAccount(username: string) {
    router.patch(
        SwitchAccountController.url(),
        { username },
        {
            preserveScroll: false,
        },
    );
}
</script>

<template>
    <header class="flex h-12 shrink-0 items-center justify-between border-b border-black/80 bg-black/10 px-4 text-sidebar-foreground">
        <Link :href="DashboardController.url()" class="text-base font-semibold tracking-tight"> mymtgo </Link>

        <div class="flex items-center gap-2">
            <DropdownMenu v-if="page.props.accounts && page.props.accounts.length > 1">
                <DropdownMenuTrigger
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
                >
                    {{ page.props.activeAccount ?? 'No account' }}
                    <ChevronDown class="size-3" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="account in page.props.accounts"
                        :key="account.id"
                        @click="switchAccount(account.username)"
                        :class="{ 'font-semibold': account.active }"
                    >
                        {{ account.username }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <span v-else-if="page.props.activeAccount" class="text-sm text-sidebar-foreground/70">
                {{ page.props.activeAccount }}
            </span>

            <Link
                :href="SettingsIndexController.url()"
                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
            >
                <Settings class="size-4" />
            </Link>
        </div>
    </header>
</template>
