<script setup lang="ts">
import { ref, watch } from 'vue';

/**
 * A table's own scrollbar sits under its last row, usually below the fold, so hidden
 * columns look cut off. This one sticks to the bottom of the screen while the table
 * is in view, and rests under the last row once that row is.
 */
const props = defineProps<{ target?: HTMLElement }>();

const bar = ref<HTMLElement>();
const contentWidth = ref(0);
const isOverflowing = ref(false);

function scrollTable(): void {
    if (props.target && bar.value) {
        props.target.scrollLeft = bar.value.scrollLeft;
    }
}

watch(
    () => props.target,
    (target, _, onCleanup) => {
        if (!target || typeof ResizeObserver === 'undefined') {
            return;
        }

        const followTable = (): void => {
            if (bar.value) {
                bar.value.scrollLeft = target.scrollLeft;
            }
        };

        // The table changes size on its own, as columns are toggled or rows load.
        const observer = new ResizeObserver(() => {
            contentWidth.value = target.scrollWidth;
            isOverflowing.value = target.scrollWidth > target.clientWidth;
        });

        observer.observe(target);

        const table = target.querySelector('table');

        if (table) {
            observer.observe(table);
        }

        target.addEventListener('scroll', followTable, { passive: true });

        onCleanup(() => {
            observer.disconnect();
            target.removeEventListener('scroll', followTable);
        });
    },
);
</script>

<template>
    <div
        v-show="isOverflowing"
        ref="bar"
        aria-hidden="true"
        tabindex="-1"
        class="bottom-0 sticky z-10 mx-px overflow-x-auto overflow-y-hidden"
        data-xq-table-scrollbar
        @scroll="scrollTable"
    >
        <div class="h-px" :style="{ width: `${contentWidth}px` }"></div>
    </div>
</template>
