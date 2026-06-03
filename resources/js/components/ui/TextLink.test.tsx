import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TextLink from './TextLink';

describe('TextLink', () => {
    it('renders an Inertia link by default with the horizon-deep tint', () => {
        render(<TextLink href="/aktivitas">Lihat detail →</TextLink>);
        const link = screen.getByRole('link', { name: /lihat detail/i });
        expect(link).toHaveAttribute('href', '/aktivitas');
        expect(link.className).toMatch(/text-horizon-deep/);
    });

    it('passes className through and keeps the base classes', () => {
        render(
            <TextLink href="/x" className="custom-class">
                Klik
            </TextLink>,
        );
        const link = screen.getByRole('link', { name: /klik/i });
        expect(link.className).toMatch(/custom-class/);
        expect(link.className).toMatch(/text-horizon-deep/);
    });
});
