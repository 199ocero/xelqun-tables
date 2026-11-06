import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createTableStore } from '../../resources/js/core/store';
import type { TableResult, TableSchema } from '../../resources/js/schema/types';

const pagination = { page: 1, perPage: 10, total: 30, lastPage: 3, options: [10] };

function schema(): TableSchema {
    return {
        $xq: 1,
        pkg: '0.1.0',
        id: 'orders',
        token: 'token',
        endpoints: { query: '/query' },
        query: {},
        columns: [],
        rows: [],
        pagination,
    };
}

function result(page: number): TableResult {
    return { query: { page }, rows: [], pagination: { ...pagination, page } };
}

function response(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

/** Requests that stay open until the test answers them. */
function holdRequests() {
    const held: Array<{ signal: AbortSignal; answer: (response: Response) => void }> = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(
            (_url: string, init: RequestInit) =>
                new Promise<Response>((resolve, reject) => {
                    init.signal?.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    );
                    held.push({ signal: init.signal as AbortSignal, answer: resolve });
                }),
        ),
    );

    return held;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('table store', () => {
    it('cancels the older request and keeps the newer answer', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });

        void store.update({ page: 2 });
        void store.update({ page: 3 });

        expect(held[0].signal.aborted).toBe(true);

        held[1].answer(response(result(3)));
        await flushPromises();

        expect(store.schema.value.pagination.page).toBe(3);
        expect(store.loading.value).toBe(false);
        expect(store.error.value).toBeNull();
        expect(onChange).toHaveBeenCalledOnce();
    });

    it('ignores an answer that a reset made old', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });
        const load = store.update({ page: 2 });

        store.reset({ ...schema(), id: 'new' });
        await load;

        expect(store.schema.value.id).toBe('new');
        expect(store.loading.value).toBe(false);
        expect(store.error.value).toBeNull();
        expect(onChange).not.toHaveBeenCalled();
        expect(held).toHaveLength(1);
    });

    it('keeps a change made while another is still loading', async () => {
        const held = holdRequests();
        const store = createTableStore(schema());

        void store.update({ per_page: 25, page: 1 });
        void store.update({ page: 2 });
        held[1].answer(response(result(2)));
        await flushPromises();

        const sent = (fetch as any).mock.calls[1][1].body;

        expect(JSON.parse(sent).query).toEqual({ per_page: 25, page: 2 });
    });

    it('drops the invalid state once the server has cleaned it', async () => {
        const fetchMock = vi.fn(async () => response(result(3)));

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore(schema());

        await store.load({ page: '999', junk: 'x' });
        await store.update({ per_page: 25 });

        expect(JSON.parse((fetchMock.mock.calls[1] as any[])[1].body).query).toEqual({
            page: 3,
            per_page: 25,
        });
    });

    it('ignores an answer after it is disposed', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });

        void store.update({ page: 2 });
        store.dispose();
        await flushPromises();

        expect(held[0].signal.aborted).toBe(true);
        expect(store.error.value).toBeNull();
        expect(store.loading.value).toBe(false);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('says an old page has expired', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response({}, 419)),
        );

        const store = createTableStore(schema());

        await store.update({ page: 2 });

        expect(store.error.value).toContain('expired');
        expect(store.loading.value).toBe(false);
    });

    it('keeps the rows on screen when a request fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response({}, 500)),
        );

        const store = createTableStore({
            ...schema(),
            rows: [{ key: '1', cells: {} }],
        });

        await store.update({ page: 2 });

        expect(store.schema.value.rows).toHaveLength(1);
        expect(store.error.value).toBe('Could not load the records.');
    });

    it('merges a change into the current state', async () => {
        const fetchMock = vi.fn(async () => response(result(1)));

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore({ ...schema(), query: { per_page: 25, page: 2 } });

        await store.update({ page: 3 });

        expect(JSON.parse((fetchMock.mock.calls[0] as any[])[1].body).query).toEqual({
            per_page: 25,
            page: 3,
        });
    });
});
