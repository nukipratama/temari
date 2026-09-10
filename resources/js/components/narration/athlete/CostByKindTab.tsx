import type { CostByKindRow } from '@/pages/Narration/types';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Card from '@/components/ui/LegacyCard';

import { formatCost, formatCount } from './format';

interface CostByKindTabProps {
    rows: CostByKindRow[];
    currency: string;
}

/** What each narrator costs this athlete, over the three windows the ceilings care about. */
export default function CostByKindTab({
    rows,
    currency,
}: Readonly<CostByKindTabProps>) {
    if (rows.length === 0) {
        return (
            <EmptyPanel
                title="no spend in the last 30 days"
                body="this athlete has not billed a narration recently."
                className="mt-6"
            />
        );
    }

    return (
        <Card
            as="section"
            tone="card"
            padding="panel"
            className="mt-6 overflow-x-auto bg-popover"
        >
            <table className="w-full min-w-[360px] text-left text-sm">
                <thead>
                    <tr className="font-mono text-xs font-semibold uppercase tracking-wider text-text-3">
                        <th scope="col" className="py-2">
                            kind
                        </th>
                        <th scope="col" className="py-2 text-right">
                            today
                        </th>
                        <th scope="col" className="py-2 text-right">
                            7 days
                        </th>
                        <th scope="col" className="py-2 text-right">
                            30 days
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.kind} className="border-t border-border">
                            <th
                                scope="row"
                                className="py-2 font-mono text-xs font-semibold uppercase text-foreground"
                            >
                                {row.kind}
                            </th>
                            <Cell
                                cost={row.today.cost}
                                calls={row.today.calls}
                                currency={currency}
                            />
                            <Cell
                                cost={row.week.cost}
                                calls={row.week.calls}
                                currency={currency}
                            />
                            <Cell
                                cost={row.month.cost}
                                calls={row.month.calls}
                                currency={currency}
                            />
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}

function Cell({
    cost,
    calls,
    currency,
}: Readonly<{ cost: number; calls: number; currency: string }>) {
    return (
        <td className="py-2 text-right tabular-nums">
            <span className="block text-foreground">
                {formatCost(cost, currency)}
            </span>
            <span className="block font-mono text-xs text-text-3">
                {formatCount(calls)} calls
            </span>
        </td>
    );
}
