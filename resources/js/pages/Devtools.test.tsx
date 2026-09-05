import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Devtools from './Devtools';

describe('Devtools', () => {
    it('links to Design, AI Usage, Horizon and Pulse', () => {
        render(<Devtools />);

        expect(screen.getByRole('link', { name: /Design/ })).toHaveAttribute(
            'href',
            '/devtools/design',
        );

        expect(screen.getByRole('link', { name: /AI Usage/ })).toHaveAttribute(
            'href',
            '/devtools/ai-usage',
        );
        expect(screen.getByRole('link', { name: /Horizon/ })).toHaveAttribute(
            'href',
            '/devtools/horizon',
        );
        expect(screen.getByRole('link', { name: /Pulse/ })).toHaveAttribute(
            'href',
            '/devtools/pulse',
        );
    });
});
