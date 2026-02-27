<script setup lang="ts">
import { computed } from 'vue';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

const props = defineProps<{
    breadcrumbs?: { label: string; href?: string }[];
}>();

const backHref = computed(() => {
    if (!props.breadcrumbs?.length) return undefined;
    for (let i = props.breadcrumbs.length - 1; i >= 0; i--) {
        if (props.breadcrumbs[i].href) return props.breadcrumbs[i].href;
    }
    return undefined;
});
</script>

<template>
    <header
        class="flex h-12 shrink-0 items-center gap-2 border-b transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-(--header-height)"
    >
        <div class="flex w-full items-center gap-1 px-4 lg:gap-2 lg:px-6">
            <SidebarTrigger class="-ml-1" />
            <Separator orientation="vertical" class="mx-2 data-[orientation=vertical]:h-4" />

            <Link
                v-if="backHref"
                :href="backHref"
                class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground"
            >
                <ChevronLeft class="h-4 w-4" />
            </Link>

            <!-- Breadcrumbs -->
            <nav v-if="breadcrumbs?.length" class="flex items-center gap-1.5 text-sm">
                <template v-for="(crumb, i) in breadcrumbs" :key="i">
                    <span v-if="i > 0" class="text-muted-foreground select-none">/</span>
                    <Link
                        v-if="crumb.href"
                        :href="crumb.href"
                        class="text-muted-foreground hover:text-foreground transition-colors"
                    >{{ crumb.label }}</Link>
                    <span v-else class="font-medium">{{ crumb.label }}</span>
                </template>
            </nav>

            <!-- Fallback plain title -->
            <h1 v-else class="text-base font-medium">
                <slot name="title" />
            </h1>
            <!--            <div class="ml-auto flex items-center gap-2">-->
            <!--                <Button variant="ghost" as-child size="sm" class="hidden sm:flex">-->
            <!--                    <a-->
            <!--                        href="https://github.com/shadcn-ui/ui/tree/main/apps/v4/app/(examples)/dashboard"-->
            <!--                        rel="noopener noreferrer"-->
            <!--                        target="_blank"-->
            <!--                        class="dark:text-foreground"-->
            <!--                    >-->
            <!--                        GitHub-->
            <!--                    </a>-->
            <!--                </Button>-->
            <!--            </div>-->
        </div>
    </header>
</template>
