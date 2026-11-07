import type { Component } from 'vue';
import XqCheckboxCell from './cells/XqCheckboxCell.vue';
import XqColorCell from './cells/XqColorCell.vue';
import XqIconCell from './cells/XqIconCell.vue';
import XqImageCell from './cells/XqImageCell.vue';
import XqSelectCell from './cells/XqSelectCell.vue';
import XqTextCell from './cells/XqTextCell.vue';
import XqTextInputCell from './cells/XqTextInputCell.vue';
import XqToggleCell from './cells/XqToggleCell.vue';

/**
 * Forms' registry comes first, so an app can replace these. It holds forms' own
 * types too, so a name here must never match one of those.
 */
export const builtInCells: Record<string, Component> = {
    'checkbox-column': XqCheckboxCell,
    'color-column': XqColorCell,
    'icon-column': XqIconCell,
    'image-column': XqImageCell,
    'select-column': XqSelectCell,
    'text-column': XqTextCell,
    'text-input-column': XqTextInputCell,
    'toggle-column': XqToggleCell,
};
