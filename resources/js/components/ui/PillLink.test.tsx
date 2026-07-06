import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PillLink from './PillLink';

describe('PillLink', () => {
    it('renders an anchor (not a button) so it is valid inside link-free markup', () => {
        render(<PillLink href="/kartu/5">Lihat kartu</PillLink>);
        const link = screen.getByRole('link', { name: /lihat kartu/i });
        expect(link).toHaveAttribute('href', '/kartu/5');
        expect(link.tagName).toBe('A');
    });

    it('applies the pill variant classes and passes className through', () => {
        render(<PillLink href="/x" className="mt-6">Klik</PillLink>);
        const link = screen.getByRole('link', { name: /klik/i });
        expect(link.className).toMatch(/mt-6/);
        expect(link.className).toMatch(/rounded-full/);
    });

    it('applies tone-specific classes', () => {
        render(<PillLink href="/x" tone="horizon">Klik</PillLink>);
        expect(screen.getByRole('link', { name: /klik/i }).className).toContain('bg-horizon');
    });

    it('applies size-specific classes', () => {
        render(<PillLink href="/x" size="sm">Klik</PillLink>);
        expect(screen.getByRole('link', { name: /klik/i }).className).toContain('text-[13px]');
    });

    it('fires onClick when clicked', () => {
        const onClick = vi.fn();
        render(<PillLink href="/x" onClick={onClick}>Klik</PillLink>);
        fireEvent.click(screen.getByRole('link', { name: /klik/i }));
        expect(onClick).toHaveBeenCalledOnce();
    });
});
