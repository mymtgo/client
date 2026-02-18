<script setup lang="ts">
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Card, CardContent } from '@/components/ui/card';
import { router } from '@inertiajs/vue3';
import MatchesTable from '@/components/matches/MatchesTable.vue';

type Paginator<T> = { data: T[]; total: number; per_page: number };

defineProps<{
    matches: Paginator<App.Data.Front.MatchData>;
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const updatePage = (page: number) => {
    router.reload({
        data: {
            page,
        },
    });
};

</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <p v-if="!matches.total" class="text-muted-foreground py-8 text-center text-sm">No matches recorded</p>

            <MatchesTable :matches="matches.data" :archetypes="archetypes" v-if="matches.total" />
        </CardContent>

        <div class="justify-end py-2 text-right" v-if="matches.total > 24">
            <Pagination
                @update:page="updatePage"
                v-slot="{ page }"
                :items-per-page="matches.per_page"
                :total="matches.total"
                :default-page="1"
            >
                <PaginationContent v-slot="{ items }">
                    <PaginationPrevious />
                    <template v-for="(item, index) in items" :key="index">
                        <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page">
                            {{ item.value }}
                        </PaginationItem>
                    </template>
                    <PaginationNext />
                </PaginationContent>
            </Pagination>
        </div>
    </Card>
</template>
