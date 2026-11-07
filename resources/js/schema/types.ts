import type { FormSchema } from '@xelqun/forms';

export const SUPPORTED_SCHEMA_VERSIONS = [1] as const;

export interface TableColumn {
    name: string;
    type: string;
    label: string;
    sortable: boolean;
    toggleable?: boolean;
    hiddenByDefault?: boolean;
    opensInNewTab?: boolean;
    props: Record<string, any>;
}

export interface TableCell {
    state: any;
    [key: string]: any;
}

export interface TableAction {
    name: string;
    props: Record<string, any>;
    /** Set on a menu, which holds these in place of running anything. */
    actions?: TableAction[];
}

export interface TableRow {
    key: string;
    url?: string;
    /** The heading this row sits under, on a grouped table. */
    group?: string;
    cells: Record<string, TableCell>;
    actions: TableAction[];
}

export interface TablePagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    options: number[];
}

/**
 * The table's state as the URL holds it. Defaults are left out.
 */
export type TableQuery = Record<string, any>;

export interface TableResult {
    query: TableQuery;
    /** A column's name, with a leading "-" for descending. */
    sort: string | null;
    activeFilters: number;
    summaries: Record<string, Array<{ label: string | null; value: unknown }>>;
    rows: TableRow[];
    pagination: TablePagination;
}

export interface TableSchema extends TableResult {
    $xq: number;
    pkg: string;
    id: string;
    token: string;
    endpoints: { query: string; update: string; reorder: string; action: string };
    layout: {
        grid: number | null;
        striped: boolean;
        pollSeconds: number | null;
        reorderable: boolean;
    };
    headerActions: TableAction[];
    bulkActions: TableAction[];
    maxSelectable: number;
    emptyState?: { heading: string; description: string | null };
    searchable: boolean;
    filters: FormSchema | null;
    filterDefaults: Record<string, unknown>;
    messages?: Record<string, string>;
    columns: TableColumn[];
}
