<script setup lang="ts">
import { XqAction, XqActionGroup, XqIcon } from '@xelqun/forms';
import type { TableStore } from '../core/store';
import type { TableSchema } from '../schema/types';
import { actionNode } from './actionNode';

defineProps<{ schema: TableSchema; store: TableStore }>();
</script>

<template>
    <div class="gap-1 grid justify-items-center text-center" data-xq-table-empty>
        <span
            v-if="schema.emptyState?.icon"
            class="size-10 mb-2 bg-xq-muted flex items-center justify-center rounded-full"
        >
            <XqIcon :name="schema.emptyState.icon" class="size-5 text-xq-muted-foreground" />
        </span>
        <template v-if="schema.emptyState">
            <p class="font-medium text-xq-foreground">{{ schema.emptyState.heading }}</p>
            <p v-if="schema.emptyState.description">{{ schema.emptyState.description }}</p>
        </template>
        <template v-else>{{ store.t('No records found.') }}</template>
        <div
            v-if="schema.emptyStateActions?.length"
            class="gap-2 mt-3 flex flex-wrap justify-center"
        >
            <component
                :is="action.actions ? XqActionGroup : XqAction"
                v-for="action in schema.emptyStateActions"
                :key="action.name"
                :node="actionNode(action)"
                :runner="store.actionRunner({ kind: 'empty' })"
            />
        </div>
    </div>
</template>
