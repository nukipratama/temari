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

    it('renders an external anchor with target=_blank when external is true', () => {
        render(
            <TextLink href="https://strava.com" external>
                Buka di Strava ↗
            </TextLink>,
        );
        const link = screen.getByRole('link', { name: /buka di strava/i });
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
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
