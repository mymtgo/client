<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Camera, Copy, MoreHorizontal, PencilLine, Trash2 } from 'lucide-vue-next';

defineProps<{ disabled?: boolean; canDrop?: boolean }>();
const emit = defineEmits<{
    screenshot: [];
    copySummary: [];
    editNotes: [];
    drop: [];
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-7 shrink-0"
                :disabled="disabled"
                aria-label="League actions"
                @click.stop
            >
                <MoreHorizontal class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64 whitespace-nowrap">
            <DropdownMenuItem @select="emit('screenshot')">
                <Camera class="mr-2 size-4" />
                Copy screenshot of league
            </DropdownMenuItem>
            <DropdownMenuItem @select="emit('copySummary')">
                <Copy class="mr-2 size-4" />
                Copy league summary
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="emit('editNotes')">
                <PencilLine class="mr-2 size-4" />
                Edit notes
            </DropdownMenuItem>
            <DropdownMenuSeparator v-if="canDrop" />
            <DropdownMenuItem v-if="canDrop" class="text-destructive" @select="emit('drop')">
                <Trash2 class="mr-2 size-4" />
                Drop league
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
