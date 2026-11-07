<script setup lang="ts">
import { XqCheckbox, XqPopover } from '@xelqun/forms';
import { Columns3 } from 'lucide-vue-next';
import type { TableColumn } from '../schema/types';

defineProps<{ columns: TableColumn[]; hidden: Set<string>; t: (text: string) => string }>();
defineEmits<{ toggle: [name: string, isVisible: boolean] }>();
</script>

<template>
    <XqPopover align="end">
        <template #trigger>
            <button
                type="button"
                :aria-label="t('Columns')"
                class="size-9 border-xq-input hover:bg-xq-accent focus-visible:ring-xq-ring inline-flex cursor-pointer items-center justify-center rounded-full border focus-visible:ring-2 focus-visible:outline-none"
                data-xq-table-columns
            >
                <Columns3 class="size-4" aria-hidden="true" />
            </button>
        </template>
        <div class="gap-2 grid">
            <label
                v-for="column in columns"
                :key="column.name"
                class="gap-2 text-sm flex items-center"
            >
                <XqCheckbox
                    :model-value="!hidden.has(column.name)"
                    @update:model-value="$emit('toggle', column.name, $event === true)"
                />
                {{ column.label }}
            </label>
        </div>
    </XqPopover>
</template>
