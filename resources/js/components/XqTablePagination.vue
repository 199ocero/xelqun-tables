<script setup lang="ts">
import { XqButton, XqSelect } from '@xelqun/forms';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { TableStore } from '../core/store';
import type { TablePagination } from '../schema/types';

const props = defineProps<{ pagination: TablePagination; t: TableStore['t'] }>();
const emit = defineEmits<{
    page: [page: number];
    perPage: [perPage: number | 'all'];
    cursor: [cursor: string];
}>();

const range = computed(() => {
    const total = props.pagination.total ?? 0;
    const perPage = props.pagination.perPage === 'all' ? total : props.pagination.perPage;

    return {
        from: (props.pagination.page - 1) * perPage + 1,
        until: Math.min(props.pagination.page * perPage, total),
        total,
    };
});
const position = computed(() => ({
    current: props.pagination.page,
    last: props.pagination.lastPage ?? 1,
}));
const pageSizes = computed(() =>
    props.pagination.options.map((option) => ({
        value: String(option),
        label: option === 'all' ? props.t('All') : String(option),
    })),
);
/** Simple and cursor pagination don't count the rows, so they only know what's next. */
const counts = computed(() => !props.pagination.mode);
const hasPrevious = computed(() =>
    props.pagination.mode === 'cursor' ? !!props.pagination.previous : props.pagination.page > 1,
);
const hasNext = computed(() =>
    props.pagination.mode === 'cursor'
        ? !!props.pagination.next
        : props.pagination.mode === 'simple'
          ? !!props.pagination.hasMore
          : props.pagination.page < (props.pagination.lastPage ?? 1),
);

function changePerPage(value: string | number | null | undefined): void {
    emit('perPage', value === 'all' ? 'all' : Number(value));
}

function go(step: 1 | -1): void {
    if (props.pagination.mode === 'cursor') {
        emit('cursor', (step === 1 ? props.pagination.next : props.pagination.previous) as string);
    } else {
        emit('page', props.pagination.page + step);
    }
}
</script>

<template>
    <nav
        :aria-label="counts ? t('Page :current of :last', position) : t('Pagination')"
        class="gap-3 text-sm text-xq-muted-foreground flex flex-wrap items-center justify-between"
    >
        <p v-if="counts" aria-live="polite">{{ t('Showing :from to :until of :total', range) }}</p>
        <p v-else></p>
        <div class="gap-4 flex flex-wrap items-center">
            <label v-if="pageSizes.length > 1" class="gap-2 flex items-center">
                {{ t('Per page') }}
                <XqSelect
                    class="w-20"
                    :model-value="String(pagination.perPage)"
                    :options="pageSizes"
                    @update:model-value="changePerPage"
                />
            </label>
            <div class="gap-2 flex items-center">
                <XqButton
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="t('Previous page')"
                    :disabled="!hasPrevious"
                    @click="go(-1)"
                >
                    <ChevronLeft />
                </XqButton>
                <span v-if="counts">{{ t('Page :current of :last', position) }}</span>
                <XqButton
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="t('Next page')"
                    :disabled="!hasNext"
                    @click="go(1)"
                >
                    <ChevronRight />
                </XqButton>
            </div>
        </div>
    </nav>
</template>
