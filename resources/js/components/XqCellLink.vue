<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ url?: string; newTab?: boolean }>();

const tag = computed(() => {
    if (!props.url) {
        return 'div';
    }

    if (props.newTab) {
        return 'a';
    }

    // Inertia can only visit its own app, and a new tab isn't a visit.
    const isVisit =
        typeof window === 'undefined'
            ? props.url.startsWith('/')
            : new URL(props.url, window.location.href).origin === window.location.origin;

    return isVisit ? Link : 'a';
});
</script>

<template>
    <component
        :is="tag"
        :href="url"
        :target="url && newTab ? '_blank' : undefined"
        :rel="url && newTab ? 'noopener noreferrer' : undefined"
        :class="url && 'hover:underline'"
    >
        <slot />
    </component>
</template>
