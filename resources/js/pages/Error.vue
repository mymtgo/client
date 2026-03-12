<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Clock, Home, SearchX, ServerCrash, ShieldX } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = defineProps<{
    status: number;
    message?: string;
}>();

const errors: Record<number, { title: string; body: string; icon: Component }> = {
    403: { title: 'Forbidden', body: "You don't have permission to access this page.", icon: ShieldX },
    404: { title: 'Not Found', body: "The page you're looking for doesn't exist or has been moved.", icon: SearchX },
    419: { title: 'Session Expired', body: 'Your session has expired. Please try again.', icon: Clock },
    500: { title: 'Server Error', body: 'Something went wrong. Try again or restart the app.', icon: ServerCrash },
    503: { title: 'Unavailable', body: 'The app is temporarily unavailable. Please try again shortly.', icon: ServerCrash },
};

const error = computed(() => errors[props.status] ?? {
    title: 'Error',
    body: props.message ?? 'An unexpected error occurred.',
    icon: AlertTriangle,
});
</script>

<template>
    <div class="flex flex-1 items-center justify-center p-8">
        <div class="flex flex-col items-center gap-4 text-center">
            <component :is="error.icon" class="size-12 text-muted-foreground" />
            <div>
                <h1 class="text-4xl font-bold text-foreground">{{ status }}</h1>
                <p class="mt-1 text-lg font-medium text-muted-foreground">{{ error.title }}</p>
            </div>
            <p class="max-w-sm text-sm text-muted-foreground">{{ error.body }}</p>
            <div class="mt-2 flex gap-2">
                <Button variant="outline" size="sm" @click="router.visit(window.history.state?.back ?? '/')">
                    <ArrowLeft class="mr-1.5 size-3.5" />
                    Go Back
                </Button>
                <Button size="sm" @click="router.visit('/')">
                    <Home class="mr-1.5 size-3.5" />
                    Dashboard
                </Button>
            </div>
        </div>
    </div>
</template>
