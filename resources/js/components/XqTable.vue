<script setup lang="ts">
import { cn, translate, useRegistry } from '@xelqun/forms';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { SUPPORTED_SCHEMA_VERSIONS } from '../schema/types';
import type { TableSchema } from '../schema/types';
import { builtInCells } from './cells';

const props = defineProps<{ table: TableSchema; class?: HTMLAttributes['class'] }>();

const registry = useRegistry();
const isSupported = computed(() =>
    (SUPPORTED_SCHEMA_VERSIONS as readonly number[]).includes(props.table.$xq),
);

const columns = computed(() =>
    props.table.columns.map((column) => ({
        ...column,
        cell: registry?.get(column.type) ?? builtInCells[column.type],
    })),
);

function t(text: string, replacements?: Record<string, string | number>): string {
    return translate(props.table.messages, text, replacements);
}
</script>

<template>
    <div
        v-if="isSupported"
        data-xq-table
        :class="cn('rounded-xq-lg border-xq-border overflow-x-auto border', props.class)"
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
                    v-for="row in table.rows"
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
                <tr v-if="table.rows.length === 0">
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
    <p v-else role="alert" class="text-sm text-xq-destructive">
        {{ t('This table needs a newer version of @xelqun/tables.') }}
    </p>
</template>
