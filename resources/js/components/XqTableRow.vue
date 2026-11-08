<script setup lang="ts">
import { cn, XqAction, XqActionGroup, XqCheckbox } from '@xelqun/forms';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { TableStore } from '../core/store';
import type { TableColumn, TableRow } from '../schema/types';
import { actionNode } from './actionNode';
import XqCellLink from './XqCellLink.vue';

/**
 * One row, as its own component, so a change to one row or its selection
 * renders that row again and not the whole table. Every prop is a plain value
 * or an object that stays the same while the row does.
 */
const props = defineProps<{
    row: TableRow;
    index: number;
    columns: Array<TableColumn & { cell?: Component }>;
    store: TableStore;
    isSelected: boolean;
    hasSelection: boolean;
    hasRowActions: boolean;
    reorderable: boolean;
    canMoveUp: boolean;
    canMoveDown: boolean;
    titleId: string;
}>();

const runner = computed(() => props.store.actionRunner({ kind: 'record', record: props.row.key }));
const t = props.store.t;

/** A row's link is in every cell, and Tab should only stop on it once. */
function isRepeatedRowLink(name: string): boolean {
    const first = props.columns.find((column) => !props.row.cells[column.name].url);

    return Boolean(props.row.url) && !props.row.cells[name].url && first?.name !== name;
}

/** A short ripple down the top rows. Past these, waiting for it would feel slow. */
const delay = computed(() => ({
    animationDelay: `calc(var(--xq-stagger) * ${Math.min(props.index, 8)})`,
}));
</script>

<template>
    <tr
        :class="
            cn(
                'border-xq-border animate-xq-rise-in border-b transition-colors duration-(--xq-duration-fast) last:border-b-0',
                isSelected && 'bg-xq-accent/60',
            )
        "
        :style="delay"
        data-xq-table-row
    >
        <td v-if="reorderable" class="px-1 py-2">
            <div class="gap-0.5 grid">
                <button
                    type="button"
                    class="text-xq-muted-foreground hover:text-xq-foreground cursor-pointer disabled:cursor-default disabled:opacity-30"
                    :aria-label="t('Move up')"
                    :aria-describedby="titleId"
                    :disabled="!canMoveUp"
                    @click="store.moveRow(row.key, 'up')"
                >
                    <ChevronUp class="size-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="text-xq-muted-foreground hover:text-xq-foreground cursor-pointer disabled:cursor-default disabled:opacity-30"
                    :aria-label="t('Move down')"
                    :aria-describedby="titleId"
                    :disabled="!canMoveDown"
                    @click="store.moveRow(row.key, 'down')"
                >
                    <ChevronDown class="size-4" aria-hidden="true" />
                </button>
            </div>
        </td>
        <td v-if="hasSelection" class="px-3 py-2">
            <XqCheckbox
                :model-value="isSelected"
                :aria-label="t('Select row')"
                :aria-describedby="titleId"
                @update:model-value="store.toggleRow(row.key, $event)"
            />
        </td>
        <td
            v-for="(column, position) in columns"
            :id="position === 0 ? titleId : undefined"
            :key="column.name"
            :class="cn('px-3 py-2', column.props.wrap && 'min-w-60 whitespace-normal')"
        >
            <template v-if="column.cell && column.props.editable">
                <component
                    :is="column.cell"
                    :column="column"
                    :cell="row.cells[column.name]"
                    @update="store.updateCell(row.key, column.name, $event)"
                />
                <p
                    v-if="row.cells[column.name].error"
                    role="alert"
                    class="mt-1 text-xs text-xq-destructive whitespace-normal"
                    data-xq-cell-error
                >
                    {{ row.cells[column.name].error }}
                </p>
            </template>
            <XqCellLink
                v-else-if="column.cell"
                :url="row.cells[column.name].url ?? row.url"
                :new-tab="row.cells[column.name].url ? column.opensInNewTab : false"
                :quiet="!row.cells[column.name].url"
                :tabindex="isRepeatedRowLink(column.name) ? -1 : undefined"
            >
                <component :is="column.cell" :column="column" :cell="row.cells[column.name]" />
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
                    :runner="runner"
                />
            </div>
        </td>
    </tr>
</template>
