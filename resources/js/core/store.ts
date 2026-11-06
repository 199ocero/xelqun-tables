import { HttpError, postJson, reportRequestFailure, translate } from '@xelqun/forms';
import type {
    ActionModal,
    ActionResult,
    ActionRunner,
    FormSchema,
    SchemaNode,
} from '@xelqun/forms';
import { ref, shallowRef } from 'vue';
import type { TableQuery, TableResult, TableSchema } from '../schema/types';

export interface TableStoreOptions {
    /** Runs after the server answers, with the state to put in the URL. */
    onChange?: (query: TableQuery) => void;
}

export type TableStore = ReturnType<typeof createTableStore>;

/** What an action works on. The server finds the record again by itself. */
export type ActionTarget =
    | { kind: 'record'; record: string }
    | { kind: 'header' }
    | { kind: 'bulk' };

export function createTableStore(initial: TableSchema, options: TableStoreOptions = {}) {
    const schema = shallowRef(initial);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const selected = ref(new Set<string>());

    let sequence = 0;
    let request: AbortController | null = null;
    let lastQuery: TableQuery = initial.query;

    function t(text: string, replacements?: Record<string, string | number>): string {
        return translate(schema.value.messages, text, replacements);
    }

    function failureMessage(failure: unknown, fallback: string): string {
        return t(
            failure instanceof HttpError && failure.status === 419
                ? 'This table has expired. Reload the page to continue.'
                : fallback,
        );
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

            error.value = failureMessage(failure, 'Could not load the records.');
        }

        loading.value = false;
    }

    /**
     * Change part of the state. It builds on what was last asked for, because
     * a change made during a request must not undo that request.
     */
    function update(changes: TableQuery): Promise<void> {
        // Otherwise a bulk action could reach rows the viewer can no longer see.
        if ('search' in changes || 'filters' in changes) {
            clearSelection();
        }

        return load({ ...lastQuery, ...changes });
    }

    function clearSelection(): void {
        selected.value = new Set();
    }

    function toggleRow(key: string, isSelected: boolean): void {
        const next = new Set(selected.value);

        if (isSelected) {
            next.add(key);
        } else {
            next.delete(key);
        }

        selected.value = next;
    }

    function togglePage(isSelected: boolean): void {
        const next = new Set(selected.value);

        schema.value.rows.forEach((row) => (isSelected ? next.add(row.key) : next.delete(row.key)));
        selected.value = next;
    }

    /**
     * Select every row of the current search and filters. Ids come from the
     * server, so nothing is selected that this page never reached.
     */
    async function selectAll(): Promise<void> {
        const mine = sequence;

        try {
            const { keys } = await postJson<{ keys: string[] }>(schema.value.endpoints.query, {
                token: schema.value.token,
                query: lastQuery,
                keys: true,
            });

            // The search may have changed, and these rows would be out of sight.
            if (mine === sequence) {
                selected.value = new Set(keys);
            }
        } catch (failure) {
            error.value = failureMessage(failure, 'Could not load the records.');
        }
    }

    function retry(): Promise<void> {
        return load(lastQuery);
    }

    /**
     * What an action's button calls instead of a form. It sends the action's
     * name, which the server looks up, and reloads the rows after it runs.
     */
    function actionRunner(target: ActionTarget): ActionRunner {
        const send = <T>(node: SchemaNode, phase: 'mount' | 'run', data?: unknown) =>
            postJson<T>(schema.value.endpoints.action, {
                token: schema.value.token,
                action: node.key,
                phase,
                data,
                ...target,
                ...(target.kind === 'bulk' ? { records: [...selected.value] } : {}),
            });
        const report = (failure: unknown, modal?: ActionModal) => {
            if (modal) {
                reportRequestFailure(failure, modal, t);

                return;
            }

            const [message] =
                failure instanceof HttpError ? Object.values(failure.validationErrors).flat() : [];

            error.value = message ?? failureMessage(failure, 'That action could not run.');
        };

        return {
            // Reloading the rows isn't the button's business, and dimming them all would flicker.
            processing: ref(false),
            t,
            async mountAction(node) {
                try {
                    return (await send<{ form: FormSchema | null }>(node, 'mount')).form;
                } catch (failure) {
                    report(failure);

                    return null;
                }
            },
            async runAction(node, modal) {
                await modal?.settled();

                if (modal && !modal.validate()) {
                    return null;
                }

                error.value = null;

                try {
                    const response = await send<ActionResult>(node, 'run', modal?.state.data);

                    if (target.kind === 'bulk') {
                        clearSelection();
                    }

                    if (!response.redirect) {
                        await load(lastQuery);
                    }

                    return response;
                } catch (failure) {
                    report(failure, modal);

                    return null;
                }
            },
        };
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
        clearSelection();
    }

    return {
        schema,
        loading,
        error,
        selected,
        t,
        load,
        update,
        retry,
        reset,
        dispose,
        actionRunner,
        clearSelection,
        toggleRow,
        togglePage,
        selectAll,
    };
}
