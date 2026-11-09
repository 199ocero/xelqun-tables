import { translate } from '@xelqun/forms';
import { inject } from 'vue';
import type { InjectionKey } from 'vue';

type Translator = (text: string, replacements?: Record<string, string | number>) => string;

/** The table's translator, for cells, which get only their column and cell. */
export const TABLE_TRANSLATOR: InjectionKey<Translator> = Symbol('xelqun-table-translator');

export function useTableTranslator(): Translator {
    return inject(TABLE_TRANSLATOR, (text, replacements) =>
        translate(undefined, text, replacements),
    );
}
