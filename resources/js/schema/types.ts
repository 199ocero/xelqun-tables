export const SUPPORTED_SCHEMA_VERSIONS = [1] as const;

export interface TableColumn {
    name: string;
    type: string;
    label: string;
    props: Record<string, any>;
}

export interface TableCell {
    state: any;
    [key: string]: any;
}

export interface TableRow {
    key: string;
    cells: Record<string, TableCell>;
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
    rows: TableRow[];
    pagination: TablePagination;
}

export interface TableSchema extends TableResult {
    $xq: number;
    pkg: string;
    id: string;
    token: string;
    endpoints: { query: string };
    messages?: Record<string, string>;
    columns: TableColumn[];
}
