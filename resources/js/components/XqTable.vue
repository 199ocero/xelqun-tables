<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { cn, useRegistry, XqAction, XqActionGroup, XqButton, XqCheckbox } from '@xelqun/forms';
import type { SchemaNode } from '@xelqun/forms';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, onScopeDispose, watch } from 'vue';
import { createTableStore } from '../core/store';
import { isSameQuery, readQuery, writeQuery } from '../core/url';
import { SUPPORTED_SCHEMA_VERSIONS } from '../schema/types';
import type { TableAction, TableQuery, TableSchema } from '../schema/types';
import { builtInCells } from './cells';
import XqCellLink from './XqCellLink.vue';
import XqTableBulkBar from './XqTableBulkBar.vue';
import XqTableFilters from './XqTableFilters.vue';
import XqTablePagination from './XqTablePagination.vue';
import XqTableSearch from './XqTableSearch.vue';

const props = defineProps<{ table: TableSchema; class?: HTMLAttributes['class'] }>();

const registry = useRegistry();
const store = createTableStore(props.table, { onChange: syncUrl });
const { schema, loading, error, selected, t } = store;
const isSupported = computed(() =>
    (SUPPORTED_SCHEMA_VERSIONS as readonly number[]).includes(schema.value.$xq),
);
const columns = computed(() =>
    schema.value.columns.map((column) => ({
        ...column,
        cell: registry?.get(column.type) ?? builtInCells[column.type],
    })),
);

const hasRowActions = computed(() => schema.value.rows.some((row) => row.actions.length > 0));
const hasSelection = computed(() => schema.value.bulkActions.length > 0);
const selectedOnPage = computed(
    () => schema.value.rows.filter((row) => selected.value.has(row.key)).length,
);
const hasToolbar = computed(
    () =>
        schema.value.searchable ||
        schema.value.filters !== null ||
        schema.value.headerActions.length > 0,
);

/** XqAction reads a form node, and an action's key is its name here. */
function actionNode(action: TableAction): SchemaNode {
    return {
        key: action.name,
        type: action.actions ? 'action-group' : 'action',
        hidden: false,
        props: action.props,
        children: action.actions?.map(actionNode) ?? [],
        deps: [],
    };
}

function sortDirection(name: string): 'asc' | 'desc' | null {
    const { sort } = schema.value;

    return sort === name ? 'asc' : sort === `-${name}` ? 'desc' : null;
}

function ariaSort(name: string): 'ascending' | 'descending' | undefined {
    const direction = sortDirection(name);

    return direction ? (direction === 'asc' ? 'ascending' : 'descending') : undefined;
}

function toggleSort(name: string): void {
    void store.update({ sort: sortDirection(name) === 'asc' ? `-${name}` : name, page: 1 });
}

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
        <div v-if="hasToolbar" class="gap-3 flex flex-wrap items-center">
            <XqTableSearch
                v-if="schema.searchable"
                :value="schema.query.search ?? ''"
                :t="t"
                @search="store.update({ search: $event, page: 1 })"
            />
            <div class="ml-auto"></div>
            <XqTableFilters
                v-if="schema.filters"
                :key="schema.filters.token ?? undefined"
                :form="schema.filters"
                :defaults="schema.filterDefaults"
                :values="schema.query.filters"
                :active="schema.activeFilters"
                :t="t"
                @apply="store.update({ filters: $event, page: 1 })"
            />
            <component
                :is="action.actions ? XqActionGroup : XqAction"
                v-for="action in schema.headerActions"
                :key="action.name"
                :node="actionNode(action)"
                :runner="store.actionRunner({ kind: 'header' })"
            />
        </div>
        <XqTableBulkBar
            v-if="selected.size > 0"
            :count="selected.size"
            :total="schema.pagination.total"
            :max="schema.maxSelectable"
            :t="t"
            @select-all="store.selectAll()"
            @clear="store.clearSelection()"
        >
            <component
                :is="action.actions ? XqActionGroup : XqAction"
                v-for="action in schema.bulkActions"
                :key="action.name"
                :node="actionNode(action)"
                :runner="store.actionRunner({ kind: 'bulk' })"
            />
        </XqTableBulkBar>
        <div
            :class="
                cn(
                    'rounded-xq-lg border-xq-border relative overflow-x-auto border transition-opacity',
                    loading && 'opacity-60',
                )
            "
        >
            <table class="text-sm w-full">
                <thead class="border-xq-border bg-xq-muted/50 border-b">
                    <tr>
                        <th v-if="hasSelection" scope="col" class="px-3 py-2 w-px">
                            <XqCheckbox
                                :model-value="
                                    selectedOnPage > 0 && selectedOnPage === schema.rows.length
                                "
                                :indeterminate="
                                    selectedOnPage > 0 && selectedOnPage < schema.rows.length
                                "
                                :aria-label="t('Select all on this page')"
                                @update:model-value="store.togglePage($event)"
                            />
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.name"
                            scope="col"
                            :aria-sort="ariaSort(column.name)"
                            class="px-3 py-2 font-medium text-xq-muted-foreground text-left"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="gap-1 font-medium hover:text-xq-foreground inline-flex cursor-pointer items-center"
                                @click="toggleSort(column.name)"
                            >
                                {{ column.label }}
                                <ArrowUp
                                    v-if="sortDirection(column.name) === 'asc'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <ArrowDown
                                    v-else-if="sortDirection(column.name) === 'desc'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <ChevronsUpDown
                                    v-else
                                    class="size-3.5 opacity-50"
                                    aria-hidden="true"
                                />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                        <th v-if="hasRowActions" scope="col">
                            <span class="sr-only">{{ t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in schema.rows"
                        :key="row.key"
                        class="border-xq-border border-b last:border-b-0"
                    >
                        <td v-if="hasSelection" class="px-3 py-2">
                            <XqCheckbox
                                :model-value="selected.has(row.key)"
                                :aria-label="t('Select row')"
                                @update:model-value="store.toggleRow(row.key, $event)"
                            />
                        </td>
                        <td v-for="column in columns" :key="column.name" class="px-3 py-2">
                            <XqCellLink
                                v-if="column.cell"
                                :url="row.cells[column.name].url"
                                :new-tab="column.opensInNewTab"
                            >
                                <component
                                    :is="column.cell"
                                    :column="column"
                                    :cell="row.cells[column.name]"
                                />
                            </XqCellLink>
                            <span v-else class="text-xq-destructive">
                                {{ t('Unknown column type: :type', { type: column.type }) }}
                            </span>
                        </td>
                        <td v-if="hasRowActions" class="px-3 py-2">
                            <div class="gap-1 flex justify-end">
                                <component
                                    :is="action.actions ? XqActionGroup : XqAction"
                                    v-for="action in row.actions"
                                    :key="action.name"
                                    :node="actionNode(action)"
                                    :runner="
                                        store.actionRunner({ kind: 'record', record: row.key })
                                    "
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="schema.rows.length === 0">
                        <td
                            :colspan="
                                columns.length + (hasRowActions ? 1 : 0) + (hasSelection ? 1 : 0)
                            "
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
