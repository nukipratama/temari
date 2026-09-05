import type { DeploymentRow } from '@/pages/AiUsage/types';

import EmptyState from '@/components/aiusage/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';

const COLUMNS = [
    'Deployment',
    'Price in/out /1M',
    'Calls',
    'Prompt',
    'Completion',
    'Total',
    'Cost',
];

export default function DeploymentTable({
    rows,
    currency,
}: Readonly<{ rows: DeploymentRow[]; currency: string }>) {
    return (
        <DataTable
            icon="mdi:server"
            title="Breakdown per Deployment"
            subtitle="Cost per Azure model called."
            tone="accent"
            columns={COLUMNS}
            minWidth={640}
            rows={rows}
            rowKey={(row) => row.deployment}
            emptyState={<EmptyState />}
            renderRow={(row) => (
                <>
                    <Td className="font-medium text-foreground">
                        {row.deployment}
                    </Td>
                    <Td className="whitespace-nowrap text-text-2">
                        {row.inputPer1m === null || row.outputPer1m === null
                            ? '—'
                            : `${formatCost(row.inputPer1m, currency)} / ${formatCost(row.outputPer1m, currency)}`}
                    </Td>
                    <Td>{fmt(row.calls)}</Td>
                    <Td>{fmt(row.prompt)}</Td>
                    <Td>{fmt(row.completion)}</Td>
                    <Td className="font-semibold text-foreground">
                        {fmt(row.total)}
                    </Td>
                    <Td className="font-semibold text-foreground">
                        {formatCost(row.cost, currency)}
                    </Td>
                </>
            )}
        />
    );
}
