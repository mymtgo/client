# Inertia.js v3 + Laravel Data Reference

> **Load when:** User asks about Inertia v3 with Vue/TypeScript, typing page props or shared data, `useForm`, or aligning Vue types with `spatie/laravel-data` objects.

Type-safe server-driven UI with Inertia.js v3, Vue 3, and Laravel Data as the server-side payload shape.

## Contents

- [App Bootstrap](#app-bootstrap)
- [Typed Page Props](#typed-page-props)
- [Shared Data Typing](#shared-data-typing)
- [Typed `useForm`](#typed-useform)
- [Laravel Data → TypeScript Types](#laravel-data--typescript-types)

---

## App Bootstrap

```ts
// resources/js/app.ts
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} — App` : 'App'),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}.vue`);
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: '#4F46E5' },
});
```

Add a `*.vue` shim so TypeScript can resolve SFC imports:

```ts
// resources/js/env.d.ts
/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
    export default component;
}
```

---

## Typed Page Props

Page components receive props shaped by the controller's `Inertia::render(...)` call. Type them via `defineProps`:

```vue
<!-- resources/js/Pages/Products/Show.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ProductData } from '@/types/generated';

interface Props {
  product: ProductData;
  related: ProductData[];
}

const props = defineProps<Props>();
</script>

<template>
  <Head :title="props.product.name" />

  <article>
    <h1>{{ props.product.name }}</h1>
    <p>{{ props.product.tagline }}</p>
  </article>
</template>
```

On the Laravel side:

```php
use App\Data\ProductData;
use Inertia\Inertia;

Route::get('/products/{slug}', function (string $slug) {
    $product = Product::where('slug', $slug)->firstOrFail();

    return Inertia::render('Products/Show', [
        'product' => ProductData::from($product),
        'related' => ProductData::collect($product->related),
    ]);
})->name('products.show');
```

---

## Shared Data Typing

Shared props (auth user, flash messages, CSRF) are set in `HandleInertiaRequests::share()`. Mirror that shape in TS so `usePage()` is typed everywhere.

```ts
// resources/js/types/inertia.d.ts
import type { PageProps as InertiaPageProps } from '@inertiajs/core';

interface AuthUser {
    id: number;
    name: string;
    email: string;
}

interface FlashBag {
    success?: string;
    error?: string;
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps {
        auth: { user: AuthUser | null };
        flash: FlashBag;
    }
}
```

Consume it with `usePage()` — the shared props are now inferred:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);
</script>
```

---

## Typed `useForm`

`useForm` accepts a generic describing the form fields. All helpers (`.post()`, `errors`, `reset()`) stay typed.

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface LoginForm {
  email: string;
  password: string;
  remember: boolean;
}

const form = useForm<LoginForm>({
  email: '',
  password: '',
  remember: false,
});

function submit() {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  });
}
</script>

<template>
  <form @submit.prevent="submit">
    <input v-model="form.email" type="email" />
    <p v-if="form.errors.email">{{ form.errors.email }}</p>

    <input v-model="form.password" type="password" />
    <p v-if="form.errors.password">{{ form.errors.password }}</p>

    <label>
      <input v-model="form.remember" type="checkbox" />
      Remember me
    </label>

    <button :disabled="form.processing" type="submit">Sign in</button>
  </form>
</template>
```

---

## Laravel Data → TypeScript Types

`spatie/laravel-data` defines the server-side shape of Inertia responses as typed PHP classes:

```php
<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ProductData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $brand,
        public float $basePrice,
        public bool $inStock,
    ) {}
}
```

There are two ways to keep TypeScript in sync with these classes:

### 1. Hand-written types (simple projects)

Define a matching interface once and import it in pages:

```ts
// resources/js/types/generated.ts
export interface ProductData {
    slug: string;
    name: string;
    brand: string;
    basePrice: number;
    inStock: boolean;
}
```

Trade-off: zero tooling, but drifts the moment someone edits the PHP class without touching TS.

### 2. Auto-generated types with `spatie/laravel-typescript-transformer`

Install once:

```bash
composer require spatie/laravel-typescript-transformer
php artisan vendor:publish --tag="typescript-transformer-config"
```

Mark Data classes with `#[TypeScript]` (or set `auto_discover_types` in config), then generate:

```php
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductData extends Data { /* ... */ }
```

```bash
php artisan typescript:transform
```

This emits a `.d.ts` file (default: `resources/js/types/generated.d.ts`) that pages can import directly:

```ts
import type { ProductData } from '@/types/generated';
```

Wire it into `composer run dev` or a file watcher so types regenerate as `Data` classes change.

### Picking between them

| Concern | Hand-written | Auto-generated |
|---------|--------------|----------------|
| Setup cost | None | One package + command |
| Drift risk | High | Low |
| Works with nested Data/DTOs | Tedious | Handles it |
| Works with `DataCollection<T>` | Manual generics | Emits `T[]` |
| Good for | Prototypes, demos | Anything with >1 DTO |

For anything beyond a handful of pages, lean on the transformer — the whole point of Laravel Data + Inertia + TS is that props are typed end-to-end.
