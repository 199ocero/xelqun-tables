<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { cn, XqBadge, XqIcon } from '@xelqun/forms';
import { computed, onScopeDispose, ref } from 'vue';
import { useTableTranslator } from '../../core/translator';
import type { TableCell, TableColumn } from '../../schema/types';
import { badgeTones, textTones } from './tones';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();
const t = useTableTranslator();
const options = computed(() => props.column.props);

const values = computed(() =>
    (Array.isArray(props.cell.state) ? props.cell.state : [props.cell.state]).filter(
        (value) => value !== null && value !== undefined && value !== '',
    ),
);
const expanded = ref(false);
const limit = computed<number | null>(() =>
    expanded.value ? null : (options.value.limitList ?? null),
);
const shown = computed(() =>
    limit.value === null ? values.value : values.value.slice(0, limit.value),
);
const hiddenCount = computed(() => values.value.length - shown.value.length);
const isList = computed(() => !!(options.value.listWithLineBreaks || options.value.bulleted));
const canExpand = computed(() => !!options.value.expandableLimitedList && isList.value);
const clampStyle = computed(() =>
    options.value.lineClamp
        ? {
              display: '-webkit-box',
              WebkitLineClamp: options.value.lineClamp,
              WebkitBoxOrient: 'vertical' as const,
              overflow: 'hidden',
          }
        : undefined,
);

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(values.value.join(', '));
    } catch {
        return;
    }

    copied.value = true;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(
        () => (copied.value = false),
        options.value.copyMessageDuration ?? 2000,
    );
}

onScopeDispose(() => clearTimeout(copiedTimer));
</script>

<template>
    <span class="gap-0.5 inline-grid" data-xq-text-cell>
        <span
            v-if="cell.description && options.descriptionAbove"
            class="text-xs text-xq-muted-foreground"
            data-xq-cell-description
        >
            {{ cell.description }}
        </span>
        <span class="gap-1.5 flex items-start">
            <span
                v-if="values.length === 0 && options.placeholder"
                class="text-xq-muted-foreground"
                data-xq-cell-placeholder
            >
                {{ options.placeholder }}
            </span>
            <span v-else-if="options.badge" class="gap-1 flex flex-wrap">
                <XqBadge
                    v-for="(value, index) in shown"
                    :key="index"
                    variant="outline"
                    :class="badgeTones[cell.color ?? 'gray']"
                >
                    <XqIcon v-if="cell.icon" :name="cell.icon" />
                    {{ value }}
                </XqBadge>
            </span>
            <component
                :is="options.bulleted ? 'ul' : 'span'"
                v-else
                :class="
                    cn(
                        isList ? 'grid' : 'gap-1.5 inline-flex items-center',
                        options.bulleted && 'pl-4 list-disc',
                        textTones[cell.color],
                    )
                "
                :style="clampStyle"
            >
                <XqIcon v-if="cell.icon && !isList" :name="cell.icon" class="size-3.5" />
                <template v-if="isList">
                    <component
                        :is="options.bulleted ? 'li' : 'span'"
                        v-for="(value, index) in shown"
                        :key="index"
                    >
                        {{ value }}
                    </component>
                </template>
                <template v-else>{{ shown.join(', ') }}</template>
            </component>
            <button
                v-if="options.copyable && values.length"
                type="button"
                class="size-5 rounded-xq-sm text-xq-muted-foreground hover:bg-xq-accent flex shrink-0 cursor-pointer items-center justify-center"
                :aria-label="copied ? (options.copyMessage ?? t('Copied')) : t('Copy')"
                :title="copied ? (options.copyMessage ?? t('Copied')) : t('Copy')"
                data-xq-cell-copy
                @click.stop.prevent="copy"
            >
                <Check v-if="copied" class="size-3.5 text-xq-success" aria-hidden="true" />
                <Copy v-else class="size-3.5" aria-hidden="true" />
            </button>
        </span>
        <component
            :is="canExpand ? 'button' : 'span'"
            v-if="hiddenCount > 0 || expanded"
            :type="canExpand ? 'button' : undefined"
            :class="
                cn(
                    'text-xs text-xq-muted-foreground w-fit',
                    canExpand && 'cursor-pointer hover:underline',
                )
            "
            data-xq-cell-more
            @click.stop.prevent="canExpand && (expanded = !expanded)"
        >
            {{ expanded ? t('Show less') : t('and :count more', { count: hiddenCount }) }}
        </component>
        <span
            v-if="cell.description && !options.descriptionAbove"
            class="text-xs text-xq-muted-foreground"
            data-xq-cell-description
        >
            {{ cell.description }}
        </span>
        <span v-if="copied" class="sr-only" role="status">{{
            options.copyMessage ?? t('Copied')
        }}</span>
    </span>
</template>
