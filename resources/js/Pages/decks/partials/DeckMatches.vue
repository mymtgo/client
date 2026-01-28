<script setup lang="ts">
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Card, CardContent } from '@/components/ui/card';
import { router, useForm } from '@inertiajs/vue3';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import MatchesTable from '@/components/matches/MatchesTable.vue';
defineProps<{
    matches: App.Data.Front.MatchData[];
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
            <Empty v-if="!matches.total">
                <EmptyDescription>No matches recorded</EmptyDescription>
            </Empty>

            <MatchesTable :matches="matches.data" v-if="matches.total" />
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

<style scoped></style>
