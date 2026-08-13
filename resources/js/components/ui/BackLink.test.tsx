import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import BackLink from './BackLink';

describe('BackLink', () => {
    it('renders a link to href with the label', () => {
        render(<BackLink href="/cards">Collection · Cards</BackLink>);
        const link = screen.getByRole('link', { name: /collection · cards/i });
        expect(link).toHaveAttribute('href', '/cards');
    });

    it('uses the muted tint by default', () => {
        render(<BackLink href="/x">Back</BackLink>);
        expect(screen.getByRole('link', { name: /^back$/i }).className).toMatch(
            /text-ink-2/,
        );
    });

    it('uses the accent tint for empty-state CTAs', () => {
        render(
            <BackLink href="/" tone="accent">
                Back to Today
            </BackLink>,
        );
        expect(
            screen.getByRole('link', { name: /back to today/i }).className,
        ).toMatch(/text-horizon-ink/);
    });

    it('passes spacing className through', () => {
        render(
            <BackLink href="/x" className="mb-6">
                Back
            </BackLink>,
        );
        expect(screen.getByRole('link', { name: /^back$/i }).className).toMatch(
            /mb-6/,
        );
    });

    it('carries a keyboard focus ring', () => {
        render(<BackLink href="/x">Back</BackLink>);
        expect(screen.getByRole('link', { name: /^back$/i }).className).toMatch(
            /focus-ring/,
        );
    });
});
