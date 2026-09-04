import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { OriginRow } from '@/pages/AiUsage/types';

import OriginTable from './OriginTable';

const ROWS: OriginRow[] = [
    {
        origin: 'ingest',
        label: 'Ingest cascade',
        prompt: 400,
        completion: 180,
        total: 580,
        calls: 2,
        cost: 0.03,
    },
    {
        origin: 'unknown',
        label: 'Unattributed',
        prompt: 100,
        completion: 20,
        total: 120,
        calls: 1,
        cost: 0.01,
    },
];

describe('OriginTable', () => {
    it('names each origin by its label rather than its stored value', () => {
        render(<OriginTable rows={ROWS} currency="USD" />);

        expect(screen.getByText('Ingest cascade')).toBeInTheDocument();
        expect(screen.getByText('Unattributed')).toBeInTheDocument();
        expect(screen.queryByText('ingest')).not.toBeInTheDocument();
    });

    it('shows the tokens and cost each origin accounts for', () => {
        render(<OriginTable rows={ROWS} currency="USD" />);

        expect(screen.getByText('580')).toBeInTheDocument();
        expect(screen.getByText('$0.03')).toBeInTheDocument();
    });

    it('falls back to an empty state when nothing was metered in the range', () => {
        render(<OriginTable rows={[]} currency="USD" />);

        expect(screen.queryByText('Ingest cascade')).not.toBeInTheDocument();
    });
});
