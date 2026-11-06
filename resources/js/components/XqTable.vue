<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { cn, useRegistry, XqButton } from '@xelqun/forms';
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, onScopeDispose, watch } from 'vue';
import { createTableStore } from '../core/store';
import { isSameQuery, readQuery, writeQuery } from '../core/url';
import { SUPPORTED_SCHEMA_VERSIONS } from '../schema/types';
import type { TableQuery, TableSchema } from '../schema/types';
import { builtInCells } from './cells';
import XqTablePagination from './XqTablePagination.vue';

const props = defineProps<{ table: TableSchema; class?: HTMLAttributes['class'] }>();

const registry = useRegistry();
const store = createTableStore(props.table, { onChange: syncUrl });
const { schema, loading, error, t } = store;
const isSupported = computed(() =>
    (SUPPORTED_SCHEMA_VERSIONS as readonly number[]).includes(schema.value.$xq),
);
const columns = computed(() =>
    schema.value.columns.map((column) => ({
        ...column,
        cell: registry?.get(column.type) ?? builtInCells[column.type],
    })),
);

function syncUrl(query: TableQuery): void {
    const { pathname, search, hash } = window.location;

    router.replace({
        url: `${pathname}${writeQuery(search, schema.value.id, query)}${hash}`,
        preserveState: true,
        preserveScroll: true,
    });
}

// A token is new on every render, so this only runs for a new visit.
watch(
    () => props.table.token,
    () => store.reset(props.table),
);

onScopeDispose(store.dispose);

onMounted(() => {
    if (!isSupported.value) {
        return;
    }

    // Inertia restores the old prop on Back, and the URL is the newer of the two.
    const inUrl = readQuery(window.location.search, schema.value.id);

    if (!isSameQuery(schema.value.id, inUrl, schema.value.query)) {
        void store.load(inUrl);
    }
});
</script>

<template>
    <div
        v-if="isSupported"
        data-xq-table
        :aria-busy="loading"
        :class="cn('gap-3 grid', props.class)"
    >
        <div
            v-if="error"
            role="alert"
            class="gap-3 px-3 py-2 text-sm rounded-xq-md border-xq-destructive/30 bg-xq-destructive/10 text-xq-destructive flex items-center justify-between border"
        >
            {{ error }}
            <XqButton type="button" variant="outline" size="sm" @click="store.retry()">
                {{ t('Retry') }}
            </XqButton>
        </div>
        <div
            :class="
                cn(
                    'rounded-xq-lg border-xq-border overflow-x-auto border transition-opacity',
                    loading && 'opacity-60',
                )
            "
        >
            <table class="text-sm w-full">
                <thead class="border-xq-border bg-xq-muted/50 border-b">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.name"
                            scope="col"
                            class="px-3 py-2 font-medium text-xq-muted-foreground text-left"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in schema.rows"
                        :key="row.key"
                        class="border-xq-border border-b last:border-b-0"
                    >
                        <td v-for="column in columns" :key="column.name" class="px-3 py-2">
                            <component
                                :is="column.cell"
                                v-if="column.cell"
                                :column="column"
                                :cell="row.cells[column.name]"
                            />
                            <span v-else class="text-xq-destructive">
                                {{ t('Unknown column type: :type', { type: column.type }) }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="schema.rows.length === 0">
                        <td
                            :colspan="columns.length"
                            class="px-3 py-8 text-xq-muted-foreground text-center"
                        >
                            {{ t('No records found.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <XqTablePagination
            v-if="schema.pagination.total > 0"
            :pagination="schema.pagination"
            :t="t"
            @page="store.update({ page: $event })"
            @per-page="store.update({ per_page: $event, page: 1 })"
        />
    </div>
    <p v-else role="alert" class="text-sm text-xq-destructive">
        {{ t('This table needs a newer version of @xelqun/tables.') }}
    </p>
</template>
