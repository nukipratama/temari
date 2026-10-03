import { render, screen } from '@testing-library/react';
import { HeartPulse } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import StatTile, { Stat, StatDelta } from './StatTile';

describe('Stat', () => {
    it('renders the label and value, each labelled on its own', () => {
        render(<Stat label="km this week" value="18.4" />);

        expect(screen.getByText('km this week')).toBeInTheDocument();
        expect(screen.getByText('18.4')).toBeInTheDocument();
    });

    it('renders the sub line when given one', () => {
        render(
            <Stat
                label="km this week"
                value="18.4"
                sub="22.1 km by wednesday last week"
            />,
        );

        expect(
            screen.getByText('22.1 km by wednesday last week'),
        ).toBeInTheDocument();
    });

    it('omits the sub line when none is given', () => {
        render(<Stat label="km this week" value="18.4" />);

        expect(screen.queryByText(/last week/)).not.toBeInTheDocument();
    });

    it('renders a delta node beside the value', () => {
        render(
            <Stat
                label="km this week"
                value="18.4"
                delta={<StatDelta value={-3.7} unit=" km" />}
            />,
        );

        expect(screen.getByText('−3.7 km')).toBeInTheDocument();
    });
});

describe('StatDelta', () => {
    it('signs a positive change with + and the positive tone', () => {
        render(<StatDelta value={3.8} />);
        const el = screen.getByText('+3.8');
        expect(el).toHaveClass('text-horizon-ink');
    });

    it('signs a negative change with − and the negative tone', () => {
        render(<StatDelta value={-1.2} />);
        const el = screen.getByText('−1.2');
        expect(el).toHaveClass('text-ember-ink');
    });

    it('signs a zero change with ± and the flat tone', () => {
        render(<StatDelta value={0} />);
        const el = screen.getByText('±0');
        expect(el).toHaveClass('text-text-3');
    });

    it('rounds to the requested decimals before signing', () => {
        render(<StatDelta value={-0.04} decimals={1} />);
        expect(screen.getByText('±0')).toBeInTheDocument();
    });

    it('appends the unit after the magnitude', () => {
        render(<StatDelta value={-1} unit="" decimals={0} />);
        expect(screen.getByText('−1')).toBeInTheDocument();
    });
});

describe('StatTile', () => {
    it('draws the one tile MASTER specifies: secondary fill, small corner, the panel padding', () => {
        const { container } = render(<StatTile label="trimp" value="412" />);

        expect(container.firstElementChild).toHaveClass(
            'rounded-sm',
            'bg-secondary',
            'pad-panel',
        );
        expect(screen.getByText('trimp')).toHaveClass('text-label-micro');
        expect(screen.getByText('412')).toHaveClass('text-stat-sm');
    });

    it('sets the number at the dense size for a three-up row', () => {
        render(<StatTile label="spm avg" value="172" size="xs" />);

        expect(screen.getByText('172')).toHaveClass('text-sm', 'tabular-nums');
    });

    it('leads the label with its icon', () => {
        render(<StatTile label="HR" value="152" icon={HeartPulse} />);

        expect(screen.getByText('HR').firstElementChild).toHaveAttribute(
            'aria-hidden',
        );
    });

    it('renders as a list item with extra rows under the number', () => {
        render(
            <ul>
                <StatTile as="li" label="Lap 1" value="5:42">
                    <span>♡ 151</span>
                </StatTile>
            </ul>,
        );

        expect(screen.getByRole('listitem')).toHaveTextContent('Lap 1');
        expect(screen.getByRole('listitem')).toHaveTextContent('♡ 151');
    });
});
