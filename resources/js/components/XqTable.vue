<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { cn, useRegistry, XqAction, XqActionGroup, XqButton, XqCheckbox } from '@xelqun/forms';
import { ArrowDown, ArrowUp, ChevronDown, ChevronsUpDown, ChevronUp } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue';
import { useRowGlide } from '../composables/useRowGlide';
import { createTableStore } from '../core/store';
import { isSameQuery, readQuery, writeQuery } from '../core/url';
import { SUPPORTED_SCHEMA_VERSIONS } from '../schema/types';
import type { TableQuery, TableRow, TableSchema } from '../schema/types';
import { actionNode } from './actionNode';
import { builtInCells } from './cells';
import XqCellLink from './XqCellLink.vue';
import XqTableBulkBar from './XqTableBulkBar.vue';
import XqTableColumns from './XqTableColumns.vue';
import XqTableFilters from './XqTableFilters.vue';
import XqTablePagination from './XqTablePagination.vue';
import XqTableScrollbar from './XqTableScrollbar.vue';
import XqTableSearch from './XqTableSearch.vue';

const props = defineProps<{ table: TableSchema; class?: HTMLAttributes['class'] }>();

const registry = useRegistry();
const store = createTableStore(props.table, { onChange: syncUrl });
const { schema, loading, error, selected, t } = store;
const isSupported = computed(() =>
    (SUPPORTED_SCHEMA_VERSIONS as readonly number[]).includes(schema.value.$xq),
);
const { glideClass, glideStyle, glideTo, hideGlide } = useRowGlide();
const scroller = ref<HTMLElement>();

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

const columns = computed(() =>
    schema.value.columns
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

/** A short ripple down the top rows. Past these, waiting for it would feel slow. */
function staggered(index: number): { animationDelay: string } {
    return { animationDelay: `calc(var(--xq-stagger) * ${Math.min(index, 8)})` };
}

function startsGroup(index: number): boolean {
    const row = schema.value.rows[index];

    return row.group !== undefined && row.group !== schema.value.rows[index - 1]?.group;
}

/** A row's link is in every cell, and Tab should only stop on it once. */
function isRepeatedRowLink(row: TableRow, name: string): boolean {
    const first = columns.value.find((column) => !row.cells[column.name].url);

    return Boolean(row.url) && !row.cells[name].url && first?.name !== name;
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
            if (!document.hidden) {
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
                            <tr
                                :class="
                                    cn(
                                        'border-xq-border animate-xq-rise-in border-b transition-colors duration-(--xq-duration-fast) last:border-b-0',
                                        selected.has(row.key) && 'bg-xq-accent/60',
                                    )
                                "
                                :style="staggered(index)"
                                data-xq-table-row
                            >
                                <td v-if="schema.layout.reorderable" class="px-1 py-2">
                                    <div class="gap-0.5 grid">
                                        <button
                                            type="button"
                                            class="text-xq-muted-foreground hover:text-xq-foreground cursor-pointer"
                                            :aria-label="t('Move up')"
                                            @click="store.moveRow(row.key, 'up')"
                                        >
                                            <ChevronUp class="size-4" aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            class="text-xq-muted-foreground hover:text-xq-foreground cursor-pointer"
                                            :aria-label="t('Move down')"
                                            @click="store.moveRow(row.key, 'down')"
                                        >
                                            <ChevronDown class="size-4" aria-hidden="true" />
                                        </button>
                                    </div>
                                </td>
                                <td v-if="hasSelection" class="px-3 py-2">
                                    <XqCheckbox
                                        :model-value="selected.has(row.key)"
                                        :aria-label="t('Select row')"
                                        @update:model-value="store.toggleRow(row.key, $event)"
                                    />
                                </td>
                                <td
                                    v-for="column in columns"
                                    :key="column.name"
                                    :class="
                                        cn(
                                            'px-3 py-2',
                                            column.props.wrap && 'min-w-60 whitespace-normal',
                                        )
                                    "
                                >
                                    <component
                                        :is="column.cell"
                                        v-if="column.cell && column.props.editable"
                                        :column="column"
                                        :cell="row.cells[column.name]"
                                        @update="store.updateCell(row.key, column.name, $event)"
                                    />
                                    <XqCellLink
                                        v-else-if="column.cell"
                                        :url="row.cells[column.name].url ?? row.url"
                                        :new-tab="
                                            row.cells[column.name].url
                                                ? column.opensInNewTab
                                                : false
                                        "
                                        :quiet="!row.cells[column.name].url"
                                        :tabindex="
                                            isRepeatedRowLink(row, column.name) ? -1 : undefined
                                        "
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
                                                store.actionRunner({
                                                    kind: 'record',
                                                    record: row.key,
                                                })
                                            "
                                        />
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="schema.rows.length === 0">
                            <td
                                :colspan="
                                    columns.length +
                                    (hasRowActions ? 1 : 0) +
                                    (hasSelection ? 1 : 0)
                                "
                                class="px-3 py-8 text-xq-muted-foreground text-center whitespace-normal"
                            >
                                <template v-if="schema.emptyState">
                                    <p class="font-medium text-xq-foreground">
                                        {{ schema.emptyState.heading }}
                                    </p>
                                    <p v-if="schema.emptyState.description" class="mt-1">
                                        {{ schema.emptyState.description }}
                                    </p>
                                </template>
                                <template v-else>{{ t('No records found.') }}</template>
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
                                class="px-3 py-2 text-sm font-medium align-top"
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
