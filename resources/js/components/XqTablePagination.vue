<script setup lang="ts">
import { XqButton, XqSelect } from '@xelqun/forms';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import type { TableStore } from '../core/store';
import type { TablePagination } from '../schema/types';

const props = defineProps<{ pagination: TablePagination; t: TableStore['t'] }>();
const emit = defineEmits<{ page: [page: number]; perPage: [perPage: number] }>();

const range = computed(() => ({
    from: (props.pagination.page - 1) * props.pagination.perPage + 1,
    until: Math.min(props.pagination.page * props.pagination.perPage, props.pagination.total),
    total: props.pagination.total,
}));
const position = computed(() => ({
    current: props.pagination.page,
    last: props.pagination.lastPage,
}));
const pageSizes = computed(() =>
    props.pagination.options.map((option) => ({ value: String(option), label: String(option) })),
);

function changePerPage(value: string | number | null | undefined): void {
    emit('perPage', Number(value));
}
</script>

<template>
    <nav
        :aria-label="t('Page :current of :last', position)"
        class="gap-3 text-sm text-xq-muted-foreground flex flex-wrap items-center justify-between"
    >
        <p aria-live="polite">{{ t('Showing :from to :until of :total', range) }}</p>
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
                    :disabled="pagination.page <= 1"
                    @click="emit('page', pagination.page - 1)"
                >
                    <ChevronLeft />
                </XqButton>
                <span>{{ t('Page :current of :last', position) }}</span>
                <XqButton
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="t('Next page')"
                    :disabled="pagination.page >= pagination.lastPage"
                    @click="emit('page', pagination.page + 1)"
                >
                    <ChevronRight />
                </XqButton>
            </div>
        </div>
    </nav>
</template>
