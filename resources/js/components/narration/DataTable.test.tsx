import { fireEvent, render, screen } from '@testing-library/react';
import { Table } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import DataTable, { Td } from './DataTable';

/** jsdom reports 0 for every scroll metric, so the scrollable case has to be stated. */
function stubScroll(
    node: HTMLElement,
    metrics: { scrollWidth: number; clientWidth: number; scrollLeft?: number },
): void {
    Object.defineProperty(node, 'scrollWidth', {
        value: metrics.scrollWidth,
        configurable: true,
    });
    Object.defineProperty(node, 'clientWidth', {
        value: metrics.clientWidth,
        configurable: true,
    });
    Object.defineProperty(node, 'scrollLeft', {
        value: metrics.scrollLeft ?? 0,
        configurable: true,
    });
}

interface Row {
    id: number;
    name: string;
}

const rows: Row[] = [
    { id: 1, name: 'alpha' },
    { id: 2, name: 'beta' },
];

function renderTable(
    overrides: Partial<Parameters<typeof DataTable<Row>>[0]> = {},
) {
    return render(
        <DataTable<Row>
            icon={Table}
            title="Judul"
            subtitle="Sub"
            tone="accent"
            columns={['Nama', 'Nilai']}
            minWidth={640}
            rows={rows}
            rowKey={(row) => row.id}
            emptyState={<p>Kosong</p>}
            renderRow={(row) => (
                <>
                    <Td className="font-medium text-foreground">{row.name}</Td>
                    <Td>{row.id}</Td>
                </>
            )}
            {...overrides}
        />,
    );
}

describe('DataTable', () => {
    it('renders its heading, column labels and one row per item', () => {
        renderTable();

        expect(screen.getByText('Judul')).toBeInTheDocument();
        expect(screen.getByText('Sub')).toBeInTheDocument();
        expect(screen.getByText('Nama')).toBeInTheDocument();
        expect(screen.getByText('alpha')).toBeInTheDocument();
        expect(screen.getByText('beta')).toBeInTheDocument();
        expect(screen.getAllByRole('row')).toHaveLength(3);
    });

    it('applies the min-width floor so the table scrolls rather than clips', () => {
        renderTable();

        expect(screen.getByRole('table').style.minWidth).toBe('640px');
    });

    it('leaves the last column unpainted when there is nothing further right', () => {
        renderTable();

        expect(
            screen.queryByTestId('table-scroll-hint'),
        ).not.toBeInTheDocument();
    });

    it('fades the edge only while the table has more to scroll to', () => {
        const { container } = renderTable();
        const scroller =
            container.querySelector<HTMLElement>('.overflow-x-auto');

        expect(scroller).not.toBeNull();
        stubScroll(scroller as HTMLElement, {
            scrollWidth: 900,
            clientWidth: 640,
        });
        fireEvent.scroll(scroller as HTMLElement);

        expect(screen.getByTestId('table-scroll-hint')).toBeInTheDocument();

        stubScroll(scroller as HTMLElement, {
            scrollWidth: 900,
            clientWidth: 640,
            scrollLeft: 260,
        });
        fireEvent.scroll(scroller as HTMLElement);

        expect(
            screen.queryByTestId('table-scroll-hint'),
        ).not.toBeInTheDocument();
    });

    it('renders the caller-supplied empty state instead of a table when there are no rows', () => {
        renderTable({ rows: [] });

        expect(screen.getByText('Kosong')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('keys rows by the caller-supplied key, so duplicate labels do not collide', () => {
        renderTable({
            rows: [
                { id: 1, name: 'sama' },
                { id: 2, name: 'sama' },
            ],
        });

        expect(screen.getAllByText('sama')).toHaveLength(2);
    });
});

describe('Td', () => {
    it('merges caller classes on top of the shared cell padding', () => {
        renderTable();

        const cell = screen.getByText('alpha');
        expect(cell.tagName).toBe('TD');
        expect(cell.className).toContain('px-5');
        expect(cell.className).toContain('font-medium');
    });
});
