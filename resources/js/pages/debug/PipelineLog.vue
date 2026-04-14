<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

const { add: toast } = useToast();

const props = defineProps<{
    lines: string[];
    file: string;
    files: string[];
    filter: string;
}>();

const selectedFile = ref(props.file);
const filterInput = ref(props.filter);
const [refreshing, startRefreshing] = useSpinGuard();

usePoll(10000);

function applyFilters() {
    router.get('/debug/pipeline-log', {
        file: selectedFile.value || undefined,
        filter: filterInput.value || undefined,
    }, { preserveScroll: true });
}

function refresh() {
    const stop = startRefreshing();
    router.reload({
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Log refreshed.', duration: 2000 }),
        onFinish: stop,
    });
}

function levelClass(line: string): string {
    if (line.includes('.ERROR:') || line.includes('.error:')) return 'text-red-400';
    if (line.includes('.WARNING:') || line.includes('.warning:')) return 'text-amber-400';
    return 'text-muted-foreground';
}
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex flex-1 flex-col overflow-hidden p-4">
            <div class="mb-4 flex shrink-0 items-center gap-2">
                <Select :modelValue="selectedFile" @update:modelValue="(val: string) => { selectedFile = val; applyFilters(); }">
                    <SelectTrigger class="h-8 w-56 text-xs">
                        <SelectValue :placeholder="files.length === 0 ? 'No log files' : 'Select log file'" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="f in files" :key="f" :value="f">{{ f }}</SelectItem>
                    </SelectContent>
                </Select>
                <Input
                    v-model="filterInput"
                    type="text"
                    placeholder="Filter log lines..."
                    class="h-8 w-64 text-xs"
                    @keyup.enter="applyFilters"
                />
                <Button size="sm" variant="outline" class="h-8" @click="applyFilters">
                    Filter
                </Button>
                <div class="flex-1" />
                <Button size="sm" variant="outline" class="h-8" @click="refresh">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </Button>
            </div>

            <div v-if="lines.length === 0" class="py-12 text-center text-sm text-muted-foreground">
                {{ files.length === 0 ? 'No pipeline log files found.' : 'No matching log entries.' }}
            </div>

            <div v-else class="min-h-0 flex-1 overflow-auto rounded-lg border border-border bg-background p-3">
                <pre class="text-xs leading-relaxed"><template
                    v-for="(line, i) in lines"
                    :key="i"
                ><span :class="levelClass(line)">{{ line }}
</span></template></pre>
            </div>
        </div>
    </div>
</template>
