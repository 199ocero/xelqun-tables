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

export interface TableSchema {
    $xq: number;
    pkg: string;
    id: string;
    columns: TableColumn[];
    rows: TableRow[];
    pagination: TablePagination;
}
