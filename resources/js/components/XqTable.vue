<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    cn,
    useRegistry,
    XqAction,
    XqActionGroup,
    XqButton,
    XqCheckbox,
    XqSelect,
} from '@xelqun/forms';
import { ArrowDown, ArrowUp, ChevronsUpDown, X } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, onScopeDispose, provide, ref, watch } from 'vue';
import { useRowGlide } from '../composables/useRowGlide';
import { createTableStore } from '../core/store';
import { TABLE_TRANSLATOR } from '../core/translator';
import { isSameQuery, readQuery, writeQuery } from '../core/url';
import { SUPPORTED_SCHEMA_VERSIONS } from '../schema/types';
import type { TableColumn, TableQuery, TableSchema } from '../schema/types';
import { actionNode } from './actionNode';
import { builtInCells } from './cells';
import XqTableBulkBar from './XqTableBulkBar.vue';
import XqTableColumns from './XqTableColumns.vue';
import XqTableEmptyState from './XqTableEmptyState.vue';
import XqTableFilters from './XqTableFilters.vue';
import XqTablePagination from './XqTablePagination.vue';
import XqTableRow from './XqTableRow.vue';
import XqTableScrollbar from './XqTableScrollbar.vue';
import XqTableSearch from './XqTableSearch.vue';

const props = defineProps<{ table: TableSchema; class?: HTMLAttributes['class'] }>();

const registry = useRegistry();
const store = createTableStore(props.table, { onChange: syncUrl });
const { schema, loading, error, canRetry, selected, t } = store;

provide(TABLE_TRANSLATOR, t);
const isSupported = computed(() =>
    (SUPPORTED_SCHEMA_VERSIONS as readonly number[]).includes(schema.value.$xq),
);
const { glideClass, glideStyle, glideTo, hideGlide } = useRowGlide();
const scroller = ref<HTMLElement>();
const root = ref<HTMLElement>();

// New rows may not reach where the pointer is, or may sit somewhere else.
watch(() => schema.value.rows, hideGlide);

const hiddenColumnsKey = `xq-table-columns:${props.table.id}`;
const hiddenColumns = ref(
    new Set(
        props.table.columns.filter((column) => column.hiddenByDefault).map((column) => column.name),
    ),
);
const toggleableColumns = computed(() =>
    schema.value.columns.filter((column) => column.toggleable),
);

function toggleColumn(name: string, isVisible: boolean): void {
    const next = new Set(hiddenColumns.value);

    if (isVisible) {
        next.delete(name);
    } else {
        next.add(name);
    }

    hiddenColumns.value = next;

    try {
        localStorage.setItem(hiddenColumnsKey, JSON.stringify([...next]));
    } catch {
        // Storage can be blocked. The choice still holds for this visit.
    }
}

// Its own computed, so a new row or cell keeps the same columns and the rows that didn't change skip rendering.
const columnDefinitions = computed(() => schema.value.columns);
const columns = computed(() =>
    columnDefinitions.value
        .filter((column) => !hiddenColumns.value.has(column.name))
        .map((column) => ({
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
        sortOptions.value.length > 0 ||
        schema.value.filters !== null ||
        toggleableColumns.value.length > 0 ||
        schema.value.headerActions.length > 0,
);

const columnCount = computed(
    () =>
        columns.value.length +
        (hasSelection.value ? 1 : 0) +
        (hasRowActions.value ? 1 : 0) +
        (schema.value.layout.reorderable ? 1 : 0),
);

const alignments: Record<string, string> = {
    start: 'text-left',
    center: 'text-center',
    end: 'text-right',
};

/** Classes and style a column's heading, cells and summary share. */
function columnClass(column: TableColumn): string {
    return alignments[column.alignment ?? 'start'] ?? alignments.start;
}

function columnStyle(column: TableColumn): Record<string, string> | undefined {
    if (column.grow) {
        return { width: '100%' };
    }

    return column.width ? { width: column.width, minWidth: column.width } : undefined;
}

/** Runs of neighbouring columns under one group heading, or none. */
const columnGroups = computed(() => {
    if (!columns.value.some((column) => column.group)) {
        return [];
    }

    const runs: Array<{ label: string | null; span: number }> = [];

    for (const column of columns.value) {
        const last = runs[runs.length - 1];

        if (last && last.label === (column.group ?? null)) {
            last.span++;
        } else {
            runs.push({ label: column.group ?? null, span: 1 });
        }
    }

    return runs;
});

const hasColumnSearch = computed(() =>
    columns.value.some((column) => column.searchableIndividually),
);

function searchColumn(name: string, search: string): void {
    const searches = { ...schema.value.query.column_search, [name]: search };

    if (search === '') {
        delete searches[name];
    }

    store.update({ column_search: searches, page: 1 });
}

function removeIndicator(fields: string[]): void {
    const filters = { ...schema.value.query.filters };

    fields.forEach((field) => (filters[field] = null));
    store.update({ filters, page: 1 });
}

/** A short ripple down the top rows. Past these, waiting for it would feel slow. */
function staggered(index: number): { animationDelay: string } {
    return { animationDelay: `calc(var(--xq-stagger) * ${Math.min(index, 8)})` };
}

function startsGroup(index: number): boolean {
    const row = schema.value.rows[index];

    return row.group !== undefined && row.group !== schema.value.rows[index - 1]?.group;
}

/** The very first and last rows can't move further, wherever the page is. */
function canMove(index: number, direction: 'up' | 'down'): boolean {
    const { page, lastPage } = schema.value.pagination;

    return direction === 'up'
        ? index > 0 || page > 1
        : index < schema.value.rows.length - 1 || page < lastPage;
}

/**
 * A poll would replace what the viewer is in the middle of: a value being
 * typed, an open dialog, or a request still on its way.
 */
function isBusy(): boolean {
    const focused = document.activeElement;

    return (
        loading.value ||
        document.querySelector('[role="dialog"][data-state="open"]') !== null ||
        (focused instanceof HTMLElement &&
            root.value?.contains(focused) === true &&
            focused.matches('input, textarea, select, [contenteditable]'))
    );
}

function sortDirection(name: string): 'asc' | 'desc' | null {
    const { sort } = schema.value;

    return sort === name ? 'asc' : sort === `-${name}` ? 'desc' : null;
}

function ariaSort(name: string): 'ascending' | 'descending' | undefined {
    const direction = sortDirection(name);

    return direction ? (direction === 'asc' ? 'ascending' : 'descending') : undefined;
}

/** Cards have no header to click, so a grid sorts from a menu. */
const sortOptions = computed(() =>
    schema.value.layout.grid
        ? columns.value
              .filter((column) => column.sortable)
              .flatMap((column) => [
                  { value: column.name, label: t(':column, ascending', { column: column.label }) },
                  {
                      value: `-${column.name}`,
                      label: t(':column, descending', { column: column.label }),
                  },
              ])
        : [],
);

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

let pollTimer: ReturnType<typeof setInterval> | undefined;

onScopeDispose(() => clearInterval(pollTimer));

onMounted(() => {
    try {
        const saved = localStorage.getItem(hiddenColumnsKey);

        if (saved) {
            hiddenColumns.value = new Set(JSON.parse(saved) as string[]);
        }
    } catch {
        // Storage can be blocked or hold something else. The defaults stand.
    }

    if (schema.value.layout.pollSeconds) {
        pollTimer = setInterval(() => {
            if (!document.hidden && !isBusy()) {
                void store.reload();
            }
        }, schema.value.layout.pollSeconds * 1000);
    }

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
        ref="root"
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
            <XqButton
                v-if="canRetry"
                type="button"
                variant="outline"
                size="sm"
                @click="store.retry()"
            >
                {{ t('Retry') }}
            </XqButton>
        </div>
        <header
            v-if="schema.heading || schema.description"
            class="gap-0.5 grid"
            data-xq-table-heading
        >
            <h2 v-if="schema.heading" class="text-base font-semibold">{{ schema.heading }}</h2>
            <p v-if="schema.description" class="text-sm text-xq-muted-foreground">
                {{ schema.description }}
            </p>
        </header>
        <div v-if="hasToolbar" class="gap-3 flex flex-wrap items-center">
            <XqTableSearch
                v-if="schema.searchable"
                :value="schema.query.search ?? ''"
                :t="t"
                @search="store.update({ search: $event, page: 1 })"
            />
            <div class="ml-auto"></div>
            <template v-if="sortOptions.length > 0">
                <label :for="`xq-${schema.id}-sort`" class="sr-only">{{ t('Sort by') }}</label>
                <XqSelect
                    :id="`xq-${schema.id}-sort`"
                    :model-value="schema.sort"
                    :options="sortOptions"
                    :placeholder="t('Sort by')"
                    class="w-56"
                    @update:model-value="
                        store.update({ sort: $event == null ? undefined : String($event), page: 1 })
                    "
                />
            </template>
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
            <XqTableColumns
                v-if="toggleableColumns.length > 0"
                :columns="toggleableColumns"
                :hidden="hiddenColumns"
                :t="t"
                @toggle="toggleColumn"
            />
            <component
                :is="action.actions ? XqActionGroup : XqAction"
                v-for="action in schema.headerActions"
                :key="action.name"
                :node="actionNode(action)"
                :runner="store.actionRunner({ kind: 'header' })"
            />
        </div>
        <div
            v-if="schema.indicators?.length"
            class="gap-2 flex flex-wrap items-center"
            data-xq-table-indicators
        >
            <span
                v-for="(indicator, index) in schema.indicators"
                :key="index"
                class="gap-1 pl-2.5 pr-1 py-0.5 text-xs border-xq-border bg-xq-muted inline-flex items-center rounded-full border"
            >
                {{ indicator.label }}
                <button
                    type="button"
                    class="size-4 hover:bg-xq-accent flex cursor-pointer items-center justify-center rounded-full"
                    :aria-label="t('Remove filter: :filter', { filter: indicator.label })"
                    @click="removeIndicator(indicator.fields)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>
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
        <div class="min-w-0">
            <div
                :class="
                    cn(
                        'rounded-xq-lg border-xq-border relative overflow-x-auto overflow-y-hidden border transition-opacity duration-(--xq-duration-base)',
                        loading && 'opacity-60',
                    )
                "
                ref="scroller"
                data-xq-table-scroll
                @mouseover="glideTo"
                @focusin="glideTo"
                @mouseleave="hideGlide"
                @focusout="hideGlide"
            >
                <span
                    v-if="!schema.layout.grid"
                    aria-hidden="true"
                    :class="glideClass"
                    :style="glideStyle"
                    data-xq-table-glide
                ></span>
                <div
                    v-if="schema.layout.grid"
                    class="gap-4 p-4 md:[grid-template-columns:repeat(var(--xq-table-grid),minmax(0,1fr))] grid"
                    :style="{ '--xq-table-grid': schema.layout.grid }"
                    data-xq-table-grid
                >
                    <article
                        v-for="(row, index) in schema.rows"
                        :key="row.key"
                        class="gap-2 p-4 border-xq-border bg-xq-card rounded-xq-md animate-xq-rise-in grid border"
                        :style="staggered(index)"
                    >
                        <div v-for="column in columns" :key="column.name" class="text-sm">
                            <p class="text-xs text-xq-muted-foreground">{{ column.label }}</p>
                            <component
                                :is="column.cell"
                                v-if="column.cell"
                                :column="column"
                                :cell="row.cells[column.name]"
                                @update="store.updateCell(row.key, column.name, $event)"
                            />
                            <p
                                v-if="row.cells[column.name]?.error"
                                role="alert"
                                class="mt-1 text-xs text-xq-destructive"
                                data-xq-cell-error
                            >
                                {{ row.cells[column.name].error }}
                            </p>
                        </div>
                        <div v-if="row.actions.length > 0" class="gap-1 flex justify-end">
                            <component
                                :is="action.actions ? XqActionGroup : XqAction"
                                v-for="action in row.actions"
                                :key="action.name"
                                :node="actionNode(action)"
                                :runner="store.actionRunner({ kind: 'record', record: row.key })"
                            />
                        </div>
                    </article>
                    <XqTableEmptyState
                        v-if="schema.rows.length === 0"
                        :schema="schema"
                        :store="store"
                        class="py-8 text-sm text-xq-muted-foreground col-span-full"
                    />
                </div>
                <table
                    v-else
                    :class="
                        cn(
                            'text-sm relative w-full whitespace-nowrap',
                            schema.layout.striped && '[&_tbody_tr:nth-child(even)]:bg-xq-muted/40',
                        )
                    "
                >
                    <thead class="border-xq-border bg-xq-muted/50 border-b">
                        <tr v-if="columnGroups.length" data-xq-table-column-groups>
                            <th v-if="schema.layout.reorderable"></th>
                            <th v-if="hasSelection"></th>
                            <th
                                v-for="(group, index) in columnGroups"
                                :key="index"
                                :colspan="group.span"
                                :scope="group.label ? 'colgroup' : undefined"
                                :class="
                                    cn(
                                        'px-3 pt-2 font-medium text-xq-foreground text-center',
                                        group.label && 'border-xq-border border-b',
                                    )
                                "
                            >
                                {{ group.label }}
                            </th>
                            <th v-if="hasRowActions"></th>
                        </tr>
                        <tr>
                            <th v-if="schema.layout.reorderable" scope="col" class="w-px">
                                <span class="sr-only">{{ t('Move up') }}</span>
                            </th>
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
                                :class="
                                    cn(
                                        'px-3 py-2 font-medium text-xq-muted-foreground',
                                        columnClass(column),
                                        column.wrapHeader && 'whitespace-normal',
                                    )
                                "
                                :style="columnStyle(column)"
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
                        <tr v-if="hasColumnSearch" data-xq-table-column-search>
                            <td v-if="schema.layout.reorderable"></td>
                            <td v-if="hasSelection"></td>
                            <td v-for="column in columns" :key="column.name" class="px-3 pb-2">
                                <XqTableSearch
                                    v-if="column.searchableIndividually"
                                    :value="schema.query.column_search?.[column.name] ?? ''"
                                    :t="t"
                                    :label="t('Search :column', { column: column.label })"
                                    @search="searchColumn(column.name, $event)"
                                />
                            </td>
                            <td v-if="hasRowActions"></td>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(row, index) in schema.rows" :key="row.key">
                            <tr v-if="startsGroup(index)" data-xq-table-group>
                                <th
                                    :colspan="columnCount"
                                    scope="colgroup"
                                    class="px-3 py-2 text-sm font-medium bg-xq-muted text-left"
                                >
                                    {{ row.group }}
                                </th>
                            </tr>
                            <XqTableRow
                                :row="row"
                                :index="index"
                                :columns="columns"
                                :store="store"
                                :is-selected="selected.has(row.key)"
                                :has-selection="hasSelection"
                                :has-row-actions="hasRowActions"
                                :reorderable="schema.layout.reorderable"
                                :can-move-up="canMove(index, 'up')"
                                :can-move-down="canMove(index, 'down')"
                                :title-id="`xq-${schema.id}-${row.key}`"
                            />
                        </template>
                        <tr v-if="schema.rows.length === 0">
                            <td
                                :colspan="columnCount"
                                class="px-3 py-8 text-xq-muted-foreground whitespace-normal"
                            >
                                <XqTableEmptyState :schema="schema" :store="store" />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="Object.keys(schema.summaries).length > 0" data-xq-table-summary>
                        <tr class="border-xq-border border-t">
                            <td v-if="schema.layout.reorderable"></td>
                            <td v-if="hasSelection"></td>
                            <td
                                v-for="column in columns"
                                :key="column.name"
                                :class="
                                    cn(
                                        'px-3 py-2 text-sm font-medium align-top',
                                        columnClass(column),
                                    )
                                "
                            >
                                <p
                                    v-for="(line, position) in schema.summaries[column.name]"
                                    :key="position"
                                >
                                    <span v-if="line.label" class="text-xq-muted-foreground">
                                        {{ line.label }}:
                                    </span>
                                    {{ line.value }}
                                </p>
                            </td>
                            <td v-if="hasRowActions"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <XqTableScrollbar :target="scroller" />
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
