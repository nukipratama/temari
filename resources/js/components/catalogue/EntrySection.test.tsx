import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CatalogueItem } from '@/lib/catalogue';

import { useSharedProps } from '@/hooks/useSharedProps';

import EntrySection from './EntrySection';

function PausedFlag() {
    return <span>{useSharedProps().aiPaused ? 'paused' : 'running'}</span>;
}

const ITEM: CatalogueItem = {
    id: 'ui-chip',
    group: 'ui',
    path: 'components/ui/Chip.tsx',
    name: 'Chip',
    description: 'A short tinted label.',
    usage: '<Chip>holding</Chip>',
    states: [
        { name: 'default', render: () => <span>holding</span> },
        {
            name: 'paused',
            sharedProps: { aiPaused: true },
            render: () => <PausedFlag />,
        },
        { name: 'opens', overlay: true, render: () => <span>sheet</span> },
    ],
    matrix: {
        axes: { tone: ['neutral', 'warning', 'sky'], size: ['sm', 'md'] },
        omitted: { sky: 'unused' },
        render: ({ tone, size }) => <span>{`${tone}/${size}`}</span>,
    },
};

describe('EntrySection', () => {
    it('heads the entry with its name, path, description and usage', () => {
        render(<EntrySection item={ITEM} />);

        expect(screen.getByRole('region', { name: 'Chip' })).toHaveAttribute(
            'id',
            'ui-chip',
        );
        expect(screen.getByText('components/ui/Chip.tsx')).toBeInTheDocument();
        expect(screen.getByText('A short tinted label.')).toBeInTheDocument();
        expect(screen.getByText('<Chip>holding</Chip>')).toBeInTheDocument();
    });

    it('draws every named state on both grounds, with its shared props', () => {
        render(<EntrySection item={ITEM} />);

        expect(screen.getAllByText('holding')).toHaveLength(2);
        expect(screen.getAllByText('paused')).toHaveLength(3);
        expect(screen.queryByText('running')).not.toBeInTheDocument();
        expect(screen.getAllByText('sheet')[0].parentElement).toHaveClass(
            'transform-gpu',
        );
    });

    it('lays the variant matrix out as rows by columns, minus the omitted values', () => {
        render(<EntrySection item={ITEM} />);

        expect(
            screen.getByRole('heading', { name: 'variants · tone × size' }),
        ).toBeInTheDocument();
        const [light] = screen.getAllByRole('table');
        expect(within(light).getByText('warning/md')).toBeInTheDocument();
        expect(within(light).queryByText(/^sky/)).not.toBeInTheDocument();
        expect(screen.getByText('not shown · sky: unused')).toBeInTheDocument();
    });

    it('lays a one-axis matrix out as a single column', () => {
        render(
            <EntrySection
                item={{
                    ...ITEM,
                    matrix: {
                        axes: { tone: ['neutral', 'warning'] },
                        render: ({ tone }) => <span>{`only ${tone}`}</span>,
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'variants · tone' }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('only warning')).toHaveLength(2);
    });
});
