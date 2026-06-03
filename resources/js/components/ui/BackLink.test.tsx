import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BackLink from './BackLink';

describe('BackLink', () => {
    it('renders a link to href with the label', () => {
        render(<BackLink href="/kartu">Koleksi · Kartu</BackLink>);
        const link = screen.getByRole('link', { name: /koleksi · kartu/i });
        expect(link).toHaveAttribute('href', '/kartu');
    });

    it('uses the muted tint by default', () => {
        render(<BackLink href="/x">Balik</BackLink>);
        expect(screen.getByRole('link', { name: /balik/i }).className).toMatch(/text-ink-2/);
    });

    it('uses the accent tint for empty-state CTAs', () => {
        render(<BackLink href="/" tone="accent">Kembali ke Hari Ini</BackLink>);
        expect(screen.getByRole('link', { name: /kembali/i }).className).toMatch(/text-horizon-deep/);
    });

    it('passes spacing className through', () => {
        render(<BackLink href="/x" className="mb-6">Balik</BackLink>);
        expect(screen.getByRole('link', { name: /balik/i }).className).toMatch(/mb-6/);
    });
});
