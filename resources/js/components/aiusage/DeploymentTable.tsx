import type { DeploymentRow } from '@/pages/AiUsage/types';

import EmptyState from '@/components/aiusage/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';

const COLUMNS = [
    'Deployment',
    'Harga in/out /1M',
    'Panggilan',
    'Prompt',
    'Completion',
    'Total',
    'Biaya',
];

export default function DeploymentTable({
    rows,
    currency,
}: Readonly<{ rows: DeploymentRow[]; currency: string }>) {
    return (
        <DataTable
            icon="mdi:server"
            title="Breakdown per Deployment"
            subtitle="Biaya per model Azure yang dipanggil."
            tone="accent"
            columns={COLUMNS}
            minWidth={640}
            rows={rows}
            rowKey={(row) => row.deployment}
            emptyState={<EmptyState />}
            renderRow={(row) => (
                <>
                    <Td className="font-medium text-ink">{row.deployment}</Td>
                    <Td className="whitespace-nowrap text-ink-2">
                        {row.inputPer1m === null || row.outputPer1m === null
                            ? '—'
                            : `${formatCost(row.inputPer1m, currency)} / ${formatCost(row.outputPer1m, currency)}`}
                    </Td>
                    <Td>{fmt(row.calls)}</Td>
                    <Td>{fmt(row.prompt)}</Td>
                    <Td>{fmt(row.completion)}</Td>
                    <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
                    <Td className="font-semibold text-ink">
                        {formatCost(row.cost, currency)}
                    </Td>
                </>
            )}
        />
    );
}
