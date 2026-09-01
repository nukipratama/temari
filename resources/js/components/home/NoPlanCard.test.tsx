import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import NoPlanCard from './NoPlanCard';

describe('NoPlanCard', () => {
    it("renders the prototype's empty plan state with a way into Plan", () => {
        render(<NoPlanCard />);

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Set up a plan/ }),
        ).toHaveAttribute('href', '/plan');
    });

    it('draws the FaceIcon the prototype puts beside the copy', () => {
        const { container } = render(<NoPlanCard />);

        expect(container.querySelector('[data-face-icon]')).toBeInTheDocument();
    });
});
