import type { FormSchema } from '@xelqun/forms';

export const SUPPORTED_SCHEMA_VERSIONS = [1] as const;

export interface TableColumn {
    name: string;
    type: string;
    label: string;
    sortable: boolean;
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
}

export interface TableRow {
    key: string;
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
    rows: TableRow[];
    pagination: TablePagination;
}

export interface TableSchema extends TableResult {
    $xq: number;
    pkg: string;
    id: string;
    token: string;
    endpoints: { query: string; action: string };
    headerActions: TableAction[];
    searchable: boolean;
    filters: FormSchema | null;
    filterDefaults: Record<string, unknown>;
    messages?: Record<string, string>;
    columns: TableColumn[];
}
