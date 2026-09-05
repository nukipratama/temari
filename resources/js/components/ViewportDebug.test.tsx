import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ViewportDebug from './ViewportDebug';

describe('ViewportDebug', () => {
    it('renders nothing without the query flag', () => {
        const { container } = render(<ViewportDebug />);
        expect(container).toBeEmptyDOMElement();
    });

    it('reports the safe-area insets when the flag is present', async () => {
        window.history.replaceState({}, '', '/?viewport-debug');
        render(<ViewportDebug />);
        expect(await screen.findByText(/^inset-top:/)).toBeInTheDocument();
        expect(screen.getByText(/^standalone:/)).toBeInTheDocument();
        window.history.replaceState({}, '', '/');
    });
});
