import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Devtools from './Devtools';

describe('Devtools', () => {
    it('links to Design, Narration, Feedback, Horizon and Pulse', () => {
        render(<Devtools />);

        expect(screen.getByRole('link', { name: /Design/ })).toHaveAttribute(
            'href',
            '/devtools/design',
        );

        expect(screen.getByRole('link', { name: /Narration/ })).toHaveAttribute(
            'href',
            '/devtools/narration',
        );
        expect(screen.getByRole('link', { name: /Feedback/ })).toHaveAttribute(
            'href',
            '/devtools/feedback',
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
