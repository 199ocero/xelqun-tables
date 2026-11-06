import { HttpError, postJson, translate } from '@xelqun/forms';
import { ref, shallowRef } from 'vue';
import type { TableQuery, TableResult, TableSchema } from '../schema/types';

export interface TableStoreOptions {
    /** Runs after the server answers, with the state to put in the URL. */
    onChange?: (query: TableQuery) => void;
}

export type TableStore = ReturnType<typeof createTableStore>;

export function createTableStore(initial: TableSchema, options: TableStoreOptions = {}) {
    const schema = shallowRef(initial);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let sequence = 0;
    let request: AbortController | null = null;
    let lastQuery: TableQuery = initial.query;

    function t(text: string, replacements?: Record<string, string | number>): string {
        return translate(schema.value.messages, text, replacements);
    }

    /**
     * Ask for the records of a state. A newer request cancels this one, so an
     * old answer that arrives late can't replace a newer one.
     */
    async function load(query: TableQuery): Promise<void> {
        request?.abort();
        request = new AbortController();

        const mine = ++sequence;

        lastQuery = query;
        loading.value = true;
        error.value = null;

        try {
            const result = await postJson<TableResult>(
                schema.value.endpoints.query,
                { token: schema.value.token, query },
                request.signal,
            );

            if (mine !== sequence) {
                return;
            }

            schema.value = { ...schema.value, ...result };
            lastQuery = result.query;
            options.onChange?.(result.query);
        } catch (failure) {
            if (mine !== sequence) {
                return;
            }

            error.value =
                failure instanceof HttpError && failure.status === 419
                    ? t('This table has expired. Reload the page to continue.')
                    : t('Could not load the records.');
        }

        loading.value = false;
    }

    /**
     * Change part of the state. It builds on what was last asked for, because
     * a change made during a request must not undo that request.
     */
    function update(changes: TableQuery): Promise<void> {
        return load({ ...lastQuery, ...changes });
    }

    function retry(): Promise<void> {
        return load(lastQuery);
    }

    /**
     * Forget any open request, so its answer can't reach a page that moved on.
     */
    function dispose(): void {
        sequence++;
        request?.abort();
        loading.value = false;
    }

    /**
     * Start over with a schema from a new page visit.
     */
    function reset(next: TableSchema): void {
        dispose();
        schema.value = next;
        lastQuery = next.query;
        error.value = null;
    }

    return { schema, loading, error, t, load, update, retry, reset, dispose };
}
