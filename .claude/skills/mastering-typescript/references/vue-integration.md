# Vue 3 Integration Reference

> **Load when:** User asks about Vue 3 with TypeScript, typed components, composables, state management, or Vue patterns.

Type-safe Vue development patterns for Vue 3.5+ with `<script setup>` and the Composition API.

## Contents

- [Component Patterns](#component-patterns)
- [Composables with TypeScript](#composables-with-typescript)
- [State Management](#state-management)
- [Event Handling](#event-handling)
- [Provide / Inject](#provide--inject)

---

## Component Patterns

### Single-File Component with `<script setup>`

```vue
<script setup lang="ts">
interface GreetingProps {
  name: string;
  age?: number;
}

const props = defineProps<GreetingProps>();
</script>

<template>
  <div>
    Hello, {{ props.name }}!
    <span v-if="props.age"> You are {{ props.age }} years old.</span>
  </div>
</template>
```

### Props with Defaults

```vue
<script setup lang="ts">
interface ButtonProps {
  label: string;
  variant?: 'primary' | 'secondary';
  disabled?: boolean;
}

const props = withDefaults(defineProps<ButtonProps>(), {
  variant: 'primary',
  disabled: false,
});
</script>

<template>
  <button :class="props.variant" :disabled="props.disabled">
    {{ props.label }}
  </button>
</template>
```

### Typed Emits

```vue
<script setup lang="ts">
interface User {
  id: string;
  name: string;
}

const emit = defineEmits<{
  select: [user: User];
  delete: [id: string];
  cancel: [];
}>();

function handleClick(user: User) {
  emit('select', user);
}
</script>
```

### Slots with Types

```vue
<script setup lang="ts" generic="T">
interface ListProps<T> {
  items: T[];
  keyExtractor: (item: T) => string;
}

const props = defineProps<ListProps<T>>();

defineSlots<{
  default(props: { item: T; index: number }): unknown;
  empty(): unknown;
}>();
</script>

<template>
  <ul v-if="props.items.length">
    <li v-for="(item, index) in props.items" :key="props.keyExtractor(item)">
      <slot :item="item" :index="index" />
    </li>
  </ul>
  <slot v-else name="empty" />
</template>
```

Usage:

```vue
<script setup lang="ts">
interface User { id: string; name: string }
const users: User[] = [{ id: '1', name: 'Alice' }];
</script>

<template>
  <List :items="users" :key-extractor="(u) => u.id">
    <template #default="{ item }">
      <span>{{ item.name }}</span>
    </template>
    <template #empty>No users found.</template>
  </List>
</template>
```

### Polymorphic Components

```vue
<script setup lang="ts" generic="T extends keyof HTMLElementTagNameMap">
interface Props<T extends keyof HTMLElementTagNameMap> {
  as?: T;
  variant?: 'primary' | 'secondary';
}

const props = withDefaults(defineProps<Props<T>>(), {
  as: 'button' as T,
  variant: 'primary',
});
</script>

<template>
  <component :is="props.as" :class="`btn btn-${props.variant}`">
    <slot />
  </component>
</template>
```

---

## Composables with TypeScript

### Reactive State

```typescript
// composables/useCounter.ts
import { ref, computed, type Ref } from 'vue';

interface UseCounterReturn {
  count: Ref<number>;
  doubled: Readonly<Ref<number>>;
  increment: () => void;
  decrement: () => void;
  reset: () => void;
}

export function useCounter(initial = 0): UseCounterReturn {
  const count = ref(initial);
  const doubled = computed(() => count.value * 2);

  return {
    count,
    doubled,
    increment: () => count.value++,
    decrement: () => count.value--,
    reset: () => { count.value = initial; },
  };
}
```

### Template Refs

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue';

const inputRef = ref<HTMLInputElement | null>(null);

onMounted(() => {
  inputRef.value?.focus();
});
</script>

<template>
  <input ref="inputRef" type="text" />
</template>
```

### Async Data Fetching Composable

```typescript
// composables/useAsync.ts
import { ref, shallowRef, type Ref } from 'vue';

interface UseAsyncReturn<T> {
  data: Ref<T | null>;
  loading: Ref<boolean>;
  error: Ref<Error | null>;
  execute: () => Promise<void>;
}

export function useAsync<T>(
  asyncFn: () => Promise<T>,
  { immediate = true } = {},
): UseAsyncReturn<T> {
  const data = shallowRef<T | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function execute() {
    loading.value = true;
    error.value = null;
    try {
      data.value = await asyncFn();
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  if (immediate) {
    void execute();
  }

  return { data, loading, error, execute };
}
```

Usage:

```vue
<script setup lang="ts">
import { useAsync } from '@/composables/useAsync';

interface User { id: string; name: string }

const { data: users, loading, error } = useAsync<User[]>(
  () => fetch('/api/users').then((r) => r.json()),
);
</script>

<template>
  <p v-if="loading">Loading…</p>
  <p v-else-if="error">{{ error.message }}</p>
  <ul v-else>
    <li v-for="user in users" :key="user.id">{{ user.name }}</li>
  </ul>
</template>
```

### Local Storage Composable

```typescript
// composables/useLocalStorage.ts
import { ref, watch, type Ref } from 'vue';

export function useLocalStorage<T>(key: string, initialValue: T): Ref<T> {
  const stored = localStorage.getItem(key);
  const state = ref<T>(
    stored !== null ? (JSON.parse(stored) as T) : initialValue,
  ) as Ref<T>;

  watch(
    state,
    (value) => {
      localStorage.setItem(key, JSON.stringify(value));
    },
    { deep: true },
  );

  return state;
}
```

---

## State Management

### Pinia with TypeScript

```typescript
// stores/auth.ts
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

interface User {
  id: string;
  name: string;
  email: string;
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null);
  const token = ref<string | null>(null);

  const isAuthenticated = computed(() => user.value !== null);

  async function login(email: string, password: string): Promise<void> {
    const response = await fetch('/api/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });
    const data = (await response.json()) as { user: User; token: string };
    user.value = data.user;
    token.value = data.token;
  }

  function logout() {
    user.value = null;
    token.value = null;
  }

  return { user, token, isAuthenticated, login, logout };
});
```

Usage in a component:

```vue
<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
const { user, isAuthenticated } = storeToRefs(authStore);
const { login, logout } = authStore;
</script>

<template>
  <div v-if="isAuthenticated">
    Welcome, {{ user?.name }}
    <button @click="logout">Log out</button>
  </div>
</template>
```

### Pinia Options API Form

```typescript
// stores/todos.ts
import { defineStore } from 'pinia';

interface Todo {
  id: string;
  text: string;
  completed: boolean;
}

interface TodosState {
  items: Todo[];
  filter: 'all' | 'active' | 'completed';
}

export const useTodosStore = defineStore('todos', {
  state: (): TodosState => ({
    items: [],
    filter: 'all',
  }),
  getters: {
    visible(state): Todo[] {
      if (state.filter === 'active') {
        return state.items.filter((t) => !t.completed);
      }
      if (state.filter === 'completed') {
        return state.items.filter((t) => t.completed);
      }
      return state.items;
    },
  },
  actions: {
    add(text: string) {
      this.items.push({
        id: crypto.randomUUID(),
        text,
        completed: false,
      });
    },
    toggle(id: string) {
      const todo = this.items.find((t) => t.id === id);
      if (todo) {
        todo.completed = !todo.completed;
      }
    },
    setFilter(filter: TodosState['filter']) {
      this.filter = filter;
    },
  },
});
```

---

## Event Handling

### Native DOM Events

```vue
<script setup lang="ts">
function handleClick(event: MouseEvent) {
  const target = event.currentTarget as HTMLButtonElement;
  console.log(target.name);
}

function handleSubmit(event: Event) {
  event.preventDefault();
  const form = event.currentTarget as HTMLFormElement;
  const formData = new FormData(form);
  console.log(Object.fromEntries(formData));
}

function handleInput(event: Event) {
  const target = event.target as HTMLInputElement;
  console.log(target.value);
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    event.preventDefault();
  }
}
</script>

<template>
  <form @submit="handleSubmit">
    <input @input="handleInput" @keydown="handleKeyDown" />
    <button type="button" name="action" @click="handleClick">Click</button>
  </form>
</template>
```

### Typed `v-model`

```vue
<script setup lang="ts">
interface Props {
  modelValue: string;
  label: string;
}

defineProps<Props>();

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();
</script>

<template>
  <label>
    {{ label }}
    <input
      :value="modelValue"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </label>
</template>
```

In Vue 3.4+ you can use the `defineModel` macro:

```vue
<script setup lang="ts">
const model = defineModel<string>({ required: true });
</script>

<template>
  <input v-model="model" />
</template>
```

### Typed Form

```vue
<script setup lang="ts">
import { reactive } from 'vue';

interface FormState {
  name: string;
  email: string;
  role: 'user' | 'admin';
}

const formData = reactive<FormState>({
  name: '',
  email: '',
  role: 'user',
});

function handleSubmit(event: Event) {
  event.preventDefault();
  console.log(formData);
}
</script>

<template>
  <form @submit="handleSubmit">
    <input v-model="formData.name" name="name" />
    <input v-model="formData.email" name="email" type="email" />
    <select v-model="formData.role">
      <option value="user">User</option>
      <option value="admin">Admin</option>
    </select>
    <button type="submit">Register</button>
  </form>
</template>
```

---

## Provide / Inject

### Typed `InjectionKey`

```typescript
// symbols.ts
import type { InjectionKey, Ref } from 'vue';

export interface ThemeContext {
  theme: Ref<'light' | 'dark'>;
  toggleTheme: () => void;
}

export const ThemeKey: InjectionKey<ThemeContext> = Symbol('Theme');
```

Provide it from an ancestor:

```vue
<script setup lang="ts">
import { provide, ref } from 'vue';
import { ThemeKey } from '@/symbols';

const theme = ref<'light' | 'dark'>('light');

function toggleTheme() {
  theme.value = theme.value === 'light' ? 'dark' : 'light';
}

provide(ThemeKey, { theme, toggleTheme });
</script>

<template>
  <slot />
</template>
```

Consume it with a typed helper:

```typescript
// composables/useTheme.ts
import { inject } from 'vue';
import { ThemeKey, type ThemeContext } from '@/symbols';

export function useTheme(): ThemeContext {
  const context = inject(ThemeKey);
  if (!context) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }
  return context;
}
```

```vue
<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';

const { theme, toggleTheme } = useTheme();
</script>

<template>
  <button @click="toggleTheme">Current: {{ theme }}</button>
</template>
```

### Generic Injection Factory

```typescript
// utils/createInjection.ts
import { inject, provide, type InjectionKey } from 'vue';

export function createInjection<T>(name: string) {
  const key: InjectionKey<T> = Symbol(name);

  function useProvide(value: T) {
    provide(key, value);
  }

  function useInject(): T {
    const value = inject(key);
    if (value === undefined) {
      throw new Error(`inject(${name}) called outside of a matching provide()`);
    }
    return value;
  }

  return [useProvide, useInject] as const;
}

// Usage
interface AuthContext {
  user: Ref<User | null>;
  login: (credentials: Credentials) => Promise<void>;
  logout: () => void;
}

const [provideAuth, useAuth] = createInjection<AuthContext>('Auth');
```
