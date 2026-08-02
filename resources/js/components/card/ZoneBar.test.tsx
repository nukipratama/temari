import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ZoneBar from './ZoneBar';

describe('ZoneBar', () => {
    it('renders a segment per present zone with a percentage title', () => {
        const { container } = render(
            <ZoneBar zonePct={{ Z1: 20, Z2: 50, Z3: 30 }} />,
        );
        expect(container.querySelector('[title="Z1: 20%"]')).not.toBeNull();
        expect(container.querySelector('[title="Z2: 50%"]')).not.toBeNull();
        expect(container.querySelector('[title="Z3: 30%"]')).not.toBeNull();
    });

    it('omits zero-percentage zones', () => {
        const { container } = render(<ZoneBar zonePct={{ Z1: 100, Z2: 0 }} />);
        expect(container.querySelector('[title="Z1: 100%"]')).not.toBeNull();
        expect(container.querySelector('[title^="Z2"]')).toBeNull();
    });

    it('renders nothing when there is no zone data', () => {
        const { container } = render(<ZoneBar zonePct={{}} />);
        expect(container.firstChild).toBeNull();
    });

    it('exposes a textual zone-distribution summary for assistive tech', () => {
        render(<ZoneBar zonePct={{ Z1: 20, Z2: 50, Z3: 30 }} />);
        expect(screen.getByText(/didominasi Z2 \(50%\)/)).toBeInTheDocument();
        expect(screen.getByText(/Z1 20%, Z2 50%, Z3 30%/)).toBeInTheDocument();
    });

    it('shows Z1..Z5 labels only when the legend is enabled', () => {
        const { rerender } = render(<ZoneBar zonePct={{ Z1: 50, Z5: 50 }} />);
        expect(screen.queryByText('Z1')).toBeNull();
        rerender(<ZoneBar zonePct={{ Z1: 50, Z5: 50 }} showLegend />);
        expect(screen.getByText('Z1')).toBeInTheDocument();
        expect(screen.getByText('Z5')).toBeInTheDocument();
    });
});
