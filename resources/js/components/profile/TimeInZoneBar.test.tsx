import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TimeInZoneBar from './TimeInZoneBar';

describe('TimeInZoneBar', () => {
    it('renders a legend entry per zone that carries time', () => {
        render(<TimeInZoneBar zones={{ Z1: 20, Z2: 60, Z3: 20 }} />);

        expect(screen.getByText(/Z1 · recovery 20%/)).toBeInTheDocument();
        expect(screen.getByText(/Z2 · easy 60%/)).toBeInTheDocument();
        expect(screen.queryByText(/Z5/)).not.toBeInTheDocument();
    });

    it('describes the whole spread to assistive tech', () => {
        render(<TimeInZoneBar zones={{ Z2: 100 }} />);

        expect(
            screen.getByRole('img', {
                name: 'Time in heart-rate zone: Z2 · easy 100%',
            }),
        ).toBeInTheDocument();
    });

    it('renders nothing when every zone is empty', () => {
        const { container } = render(
            <TimeInZoneBar zones={{ Z1: 0, Z2: 0 }} />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
