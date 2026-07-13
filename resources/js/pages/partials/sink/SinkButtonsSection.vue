<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-vue-next';
import SinkSection from './SinkSection.vue';
</script>

<template>
    <SinkSection id="buttons" title="Buttons">
        <div class="flex flex-wrap items-center gap-3">
            <Button>Primary</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="ghost">Ghost</Button>
            <Button variant="destructive"><Trash2 class="size-4" /> Destructive</Button>
            <Button variant="outline">Outline</Button>
            <Button variant="link">Link</Button>
            <Button size="sm"><Plus class="size-4" /> Small</Button>
            <Button size="lg">Large</Button>
            <Button size="icon"><Plus class="size-4" /></Button>
            <Button size="icon-sm"><Plus class="size-4" /></Button>
            <Button size="icon-lg"><Plus class="size-4" /></Button>
            <Button disabled>Disabled</Button>
        </div>
    </SinkSection>
</template>
