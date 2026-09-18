import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AskedRanResult, ChangeRow, DeltaPair, DeltaTag } from './DeltaPair';

describe('DeltaPair', () => {
    it('reads both values with the old one struck through', () => {
        render(<DeltaPair from="3.6" to="4.1 km" direction="up" />);

        const old = screen.getByText('3.6');
        expect(old).toHaveClass('line-through');
        expect(screen.getByText('4.1 km')).toBeInTheDocument();
    });

    it('points the arrow up when the value increased, coloured leaf', () => {
        const { container } = render(
            <DeltaPair from="3.6" to="4.1 km" direction="up" />,
        );

        expect(container).toHaveTextContent('↑');
        expect(screen.getByText('↑')).toHaveClass('text-leaf-ink');
    });

    it('points the arrow down when the value decreased, coloured differently from up', () => {
        const { container } = render(
            <DeltaPair from="5.9" to="4.1 km" direction="down" />,
        );

        expect(container).toHaveTextContent('↓');
        expect(screen.getByText('↓')).toHaveClass('text-ember-ink');
    });

    it('never renders the same glyph for both directions', () => {
        const { unmount } = render(
            <DeltaPair from="1" to="2" direction="up" />,
        );
        const upGlyph = screen.getByText(/[↑↓]/).textContent;
        unmount();

        render(<DeltaPair from="1" to="2" direction="down" />);
        const downGlyph = screen.getByText(/[↑↓]/).textContent;

        expect(upGlyph).not.toBe(downGlyph);
    });

    it('carries the whole delta in one accessible text run', () => {
        const { container } = render(
            <DeltaPair from="3.6" to="4.1 km" direction="up" />,
        );

        expect(container.textContent).toBe('3.6 ↑ 4.1 km');
    });
});

describe('DeltaPair neutral direction', () => {
    it('reads a type change with a neutral arrow, old value struck through', () => {
        const { container } = render(
            <DeltaPair from="tempo" to="easy" direction="neutral" />,
        );

        expect(screen.getByText('tempo')).toHaveClass('line-through');
        expect(screen.getByText('easy')).toBeInTheDocument();
        expect(container.textContent).toBe('tempo → easy');
        expect(screen.getByText('→')).toHaveClass('text-text-2');
    });
});

describe('DeltaTag', () => {
    it('renders the mono micro-label style', () => {
        render(<DeltaTag>week fit</DeltaTag>);

        expect(screen.getByText('week fit')).toHaveClass('text-label-micro');
    });
});

describe('ChangeRow', () => {
    it('labels the changed thing and tags the reason', () => {
        const { container } = render(
            <ChangeRow
                label="pace"
                from="6:00"
                to="6:15/km"
                direction="neutral"
                tag="eased"
            />,
        );

        expect(screen.getByText('pace')).toBeInTheDocument();
        expect(container).toHaveTextContent('6:00');
        expect(container).toHaveTextContent('6:15/km');
        expect(screen.getByText('eased')).toBeInTheDocument();
    });

    it('carries a distance delta with its own direction', () => {
        const { container } = render(
            <ChangeRow
                label="km"
                from="5.9"
                to="4.1"
                direction="down"
                tag="eased"
            />,
        );

        expect(screen.getByText('km')).toBeInTheDocument();
        expect(container).toHaveTextContent(/5\.9\s*↓\s*4\.1/);
    });

    it('renders a different tag for a different reason', () => {
        render(
            <ChangeRow
                label="km"
                from="8"
                to="3"
                direction="down"
                tag="week fit"
            />,
        );

        expect(screen.getByText('week fit')).toBeInTheDocument();
        expect(screen.queryByText('eased')).not.toBeInTheDocument();
    });
});

describe('AskedRanResult', () => {
    it('regroups by side, distance and pace together, every number labelled', () => {
        render(
            <AskedRanResult
                askedKm={6.4}
                askedPace="7:30/km"
                ranKm={5.3}
                ranPace="6:43/km"
            />,
        );

        expect(screen.getByText('asked 6.4 km · 7:30/km')).toBeInTheDocument();
        expect(screen.getByText('ran 5.3 km · 6:43/km')).toBeInTheDocument();
    });

    it('drops a side pace that has no figure behind it', () => {
        render(
            <AskedRanResult
                askedKm={6}
                askedPace={null}
                ranKm={0}
                ranPace={null}
            />,
        );

        expect(screen.getByText('asked 6 km')).toBeInTheDocument();
        expect(screen.getByText('ran 0 km')).toBeInTheDocument();
    });
});
