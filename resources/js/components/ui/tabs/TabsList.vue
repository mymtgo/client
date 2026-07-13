<script setup lang="ts">
import { cn } from '@/lib/utils';
import { reactiveOmit } from '@vueuse/core';
import type { TabsListProps } from 'reka-ui';
import { TabsList } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

const props = defineProps<TabsListProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = reactiveOmit(props, 'class');
</script>

<template>
    <TabsList
        data-slot="tabs-list"
        v-bind="delegatedProps"
        :class="
            cn(
                'flex w-fit items-center gap-6 border-b border-border text-muted-foreground',
                props.class,
            )
        "
    >
        <slot />
    </TabsList>
</template>
