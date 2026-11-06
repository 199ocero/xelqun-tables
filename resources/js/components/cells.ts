import type { Component } from 'vue';
import XqTextCell from './cells/XqTextCell.vue';

/**
 * Forms' registry comes first, so an app can replace these. It holds forms' own
 * types too, so a name here must never match one of those.
 */
export const builtInCells: Record<string, Component> = {
    'text-column': XqTextCell,
};
