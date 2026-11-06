<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/** `quiet` is for a link the whole row shares, which the row's hover already shows. */
const props = defineProps<{ url?: string; newTab?: boolean; quiet?: boolean }>();

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
        :class="url && (quiet ? '-mx-3 -my-2 px-3 py-2 block' : 'hover:underline')"
    >
        <slot />
    </component>
</template>
