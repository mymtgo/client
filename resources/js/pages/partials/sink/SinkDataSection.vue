<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer } from '@/components/ui/chart';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Pagination, PaginationContent, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import SortableHeader from '@/components/SortableHeader.vue';
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { AlertTriangle, ChevronsUpDown, Inbox } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';

const rows = [
    { id: 1, opponent: 'Mono Red Aggro', result: 'Win', duration: '18m', date: '2 Jul' },
    { id: 2, opponent: 'UW Control', result: 'Loss', duration: '24m', date: '1 Jul' },
    { id: 3, opponent: 'Jund Midrange', result: 'Win', duration: '31m', date: '30 Jun' },
    { id: 4, opponent: 'Dimir Reanimator', result: 'Win', duration: '12m', date: '29 Jun' },
];
const sortBy = ref<string | null>('date');
const sortDir = ref<'asc' | 'desc'>('desc');

const collapsibleOpen = ref(false);

const chartConfig = { winrate: { label: 'Win rate', color: 'var(--color-success)' } } satisfies ChartConfig;
const chartData = Array.from({ length: 10 }, (_, i) => ({
    date: new Date(2026, 5, i * 3 + 1),
    winrate: [48, 51, 55, 53, 58, 60, 57, 62, 64, 61][i],
}));
const formatTick = (ms: number) => new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
</script>

<template>
    <SinkSection id="data" title="Data">
        <Card class="gap-0 overflow-hidden p-0">
            <CardContent class="px-0">
                <Table>
                    <TableHeader class="bg-muted">
                        <TableRow>
                            <TableHead><SortableHeader label="Opponent" column="opponent" :sort-by="sortBy" :sort-dir="sortDir" /></TableHead>
                            <TableHead><SortableHeader label="Result" column="result" :sort-by="sortBy" :sort-dir="sortDir" /></TableHead>
                            <TableHead><SortableHeader label="Duration" column="duration" :sort-by="sortBy" :sort-dir="sortDir" /></TableHead>
                            <TableHead><SortableHeader label="Date" column="date" :sort-by="sortBy" :sort-dir="sortDir" /></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in rows" :key="row.id">
                            <TableCell>{{ row.opponent }}</TableCell>
                            <TableCell>{{ row.result }}</TableCell>
                            <TableCell>{{ row.duration }}</TableCell>
                            <TableCell>{{ row.date }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-3">
            <Badge>Default</Badge>
            <Badge variant="secondary">Secondary</Badge>
            <Badge variant="destructive">Destructive</Badge>
            <Badge variant="success">Success</Badge>
            <Badge variant="outline">Outline</Badge>
        </div>

        <div class="flex items-center gap-3">
            <Avatar>
                <AvatarFallback>AR</AvatarFallback>
            </Avatar>
            <Avatar class="size-10">
                <AvatarFallback>MU</AvatarFallback>
            </Avatar>
        </div>

        <Pagination v-slot="{ page }" :items-per-page="10" :total="40" :default-page="1">
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

        <Tabs default-value="overview" class="flex flex-col gap-3">
            <TabsList>
                <TabsTrigger value="overview">Overview</TabsTrigger>
                <TabsTrigger value="matches">Matches</TabsTrigger>
                <TabsTrigger value="games">Games</TabsTrigger>
            </TabsList>
            <TabsContent value="overview" class="text-sm text-muted-foreground">Overview tab content.</TabsContent>
            <TabsContent value="matches" class="text-sm text-muted-foreground">Matches tab content.</TabsContent>
            <TabsContent value="games" class="text-sm text-muted-foreground">Games tab content.</TabsContent>
        </Tabs>

        <Collapsible v-model:open="collapsibleOpen" class="w-80 rounded-md border px-3 py-2">
            <CollapsibleTrigger class="flex w-full items-center justify-between text-sm font-medium">
                Sideboard notes
                <ChevronsUpDown class="size-4 text-muted-foreground" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pt-2 text-sm text-muted-foreground"> -2 Lightning Bolt, +2 Rest in Peace vs graveyard decks. </CollapsibleContent>
        </Collapsible>

        <Separator />

        <Alert variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>Watcher stopped</AlertTitle>
            <AlertDescription>Start the file watcher in Settings to resume tracking.</AlertDescription>
        </Alert>

        <ResolvedNote
            tag="rule set"
            note="Loading roles: skeleton shaped like its content; spinner for actions and inline waits only. Never combined in the same surface."
        />
        <div class="flex flex-wrap items-start gap-9">
            <Card class="w-64 p-4">
                <div class="flex flex-col gap-3">
                    <Skeleton class="h-3 w-3/5" />
                    <Skeleton class="h-6 w-2/5" />
                    <Skeleton class="h-2.5 w-4/5" />
                </div>
            </Card>
            <div class="flex items-center gap-4">
                <Button disabled><Spinner class="size-[15px]" /> Syncing…</Button>
                <span class="inline-flex items-center gap-2 text-[13px] text-muted-foreground"><Spinner class="size-[15px]" /> Watching file…</span>
            </div>
        </div>

        <Empty class="rounded-md border border-dashed">
            <EmptyMedia variant="icon">
                <Inbox class="size-6" />
            </EmptyMedia>
            <EmptyTitle>No matches recorded</EmptyTitle>
            <EmptyDescription>Start the file watcher in Settings to begin tracking your MTGO matches.</EmptyDescription>
        </Empty>

        <div>
            <h3 class="mb-3 text-sm font-medium text-muted-foreground">Chart (minimal ChartContainer + VisLine usage)</h3>
            <ChartContainer :config="chartConfig" class="h-64 w-full max-w-xl">
                <VisXYContainer :data="chartData" :y-domain="[0, 100]">
                    <VisLine :x="(d: { date: Date; winrate: number }) => d.date" :y="(d: { date: Date; winrate: number }) => d.winrate" />
                    <VisAxis type="x" :tick-format="formatTick" />
                    <VisAxis type="y" :grid-line="true" />
                </VisXYContainer>
            </ChartContainer>
        </div>
    </SinkSection>
</template>
