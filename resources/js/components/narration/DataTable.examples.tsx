import { Coins } from 'lucide-react';

import DataTable, { Td } from '@/components/narration/DataTable';
import { type CatalogueEntry } from '@/lib/catalogue';

interface Row {
    kind: string;
    calls: number;
    cost: string;
}

const ROWS: readonly Row[] = [
    { kind: 'weekly recap', calls: 42, cost: '$0.31' },
    { kind: 'run insight', calls: 118, cost: '$0.94' },
];

function Table({ rows }: Readonly<{ rows: readonly Row[] }>) {
    return (
        <DataTable
            icon={Coins}
            title="spend by kind"
            subtitle="the last 7 days"
            tone="brand"
            columns={['kind', 'calls', 'cost']}
            minWidth={420}
            rows={rows}
            rowKey={(row) => row.kind}
            renderRow={(row) => (
                <>
                    <Td className="text-foreground">{row.kind}</Td>
                    <Td>{row.calls}</Td>
                    <Td>{row.cost}</Td>
                </>
            )}
            emptyState={
                <p className="mt-4 text-sm text-text-2">no calls yet.</p>
            }
        />
    );
}

export default {
    name: 'DataTable',
    description:
        "The operator screens' table: a SectionHeading, then rows that scroll sideways on a phone with a fade hinting at more. Td is its cell.",
    usage: `<DataTable
    icon={Coins}
    title="spend by kind"
    subtitle="the last 7 days"
    tone="brand"
    columns={['kind', 'calls', 'cost']}
    minWidth={420}
    rows={rows}
    rowKey={(row) => row.kind}
    renderRow={(row) => <Td>{row.kind}</Td>}
    emptyState={<EmptyState />}
/>`,
    states: [
        { name: 'with rows', render: () => <Table rows={ROWS} /> },
        { name: 'empty', render: () => <Table rows={[]} /> },
    ],
} satisfies CatalogueEntry;
