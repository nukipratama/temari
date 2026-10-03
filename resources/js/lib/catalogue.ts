import type { ReactNode } from 'react';

import type { SharedProps } from '@/types/inertia';

export interface CatalogueState {
    name: string;
    render: () => ReactNode;
    /** Shared Inertia props the component reads, merged over the page's own. */
    sharedProps?: Partial<SharedProps>;
    /** The example opens an overlay, so its frame gets a fixed height and hosts the portal. */
    overlay?: boolean;
}

export interface CatalogueMatrix {
    /** One or two axes of a variant map in lib/variants.ts: rows, then columns. */
    axes: Readonly<Record<string, readonly string[]>>;
    /** Values left out of the matrix, each with the reason shown under it. */
    omitted?: Readonly<Record<string, string>>;
    render: (variant: Readonly<Record<string, string>>) => ReactNode;
}

/** The default export of a co-located `*.examples.tsx`. */
export interface CatalogueEntry {
    name: string;
    description: string;
    usage: string;
    states: readonly CatalogueState[];
    matrix?: CatalogueMatrix;
}

export interface CatalogueItem extends CatalogueEntry {
    id: string;
    group: string;
    path: string;
}

const LEADING_GROUPS = ['ui', 'temari', 'shared'];

function groupRank(group: string): number {
    const index = LEADING_GROUPS.indexOf(group);
    return index === -1 ? LEADING_GROUPS.length : index;
}

/**
 * Turns `import.meta.glob` results, keyed relative to `components/catalogue/`,
 * into sorted items: ui first, then temari, the top-level shared components,
 * and the feature folders alphabetically.
 */
export function catalogueItems(
    modules: Readonly<Record<string, { default: CatalogueEntry }>>,
): CatalogueItem[] {
    return Object.entries(modules)
        .map(([key, module]) => {
            const relative = key.replace(/^\.\.\//, '');
            const folder = relative.includes('/')
                ? relative.slice(0, relative.lastIndexOf('/'))
                : '';
            const group = folder === '' ? 'shared' : folder;
            const entry = module.default;

            return {
                ...entry,
                id: `${group}-${entry.name}`.toLowerCase().replace(/\W+/g, '-'),
                group,
                path: `components/${relative.replace(/\.examples\.tsx$/, '.tsx')}`,
            };
        })
        .sort(
            (a, b) =>
                groupRank(a.group) - groupRank(b.group) ||
                a.group.localeCompare(b.group) ||
                a.name.localeCompare(b.name),
        );
}

export function matchesQuery(item: CatalogueItem, query: string): boolean {
    const needle = query.trim().toLowerCase();
    if (needle === '') {
        return true;
    }

    return [item.name, item.group, item.description, item.path].some((text) =>
        text.toLowerCase().includes(needle),
    );
}

/** Items in display order, bucketed by group. */
export function groupItems(
    items: readonly CatalogueItem[],
): Array<[string, CatalogueItem[]]> {
    const groups = new Map<string, CatalogueItem[]>();
    for (const item of items) {
        groups.set(item.group, [...(groups.get(item.group) ?? []), item]);
    }

    return [...groups.entries()];
}

/** The chosen axes of a cva variant map, as the value names each one accepts. */
export function axesOf<Map extends Record<string, Record<string, string>>>(
    map: Map,
    axes: ReadonlyArray<keyof Map & string>,
): Record<string, string[]> {
    return Object.fromEntries(
        axes.map((axis) => [axis, Object.keys(map[axis])]),
    );
}
