import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Eyebrow from './Eyebrow';

describe('Eyebrow', () => {
    it('renders a div with the default size, tracking, and weight', () => {
        render(<Eyebrow>Label</Eyebrow>);
        const el = screen.getByText('Label');
        expect(el.tagName).toBe('DIV');
        expect(el.className).toMatch(/font-mono/);
        expect(el.className).toMatch(/uppercase/);
        expect(el.className).toMatch(/text-\[11px\]/);
        expect(el.className).toMatch(/tracking-\[0\.14em\]/);
        expect(el.className).toMatch(/font-bold/);
    });

    it.each(['div', 'span', 'h3', 'dt', 'footer'] as const)('renders as="%s"', (tag) => {
        render(<Eyebrow as={tag}>Label {tag}</Eyebrow>);
        expect(screen.getByText(`Label ${tag}`).tagName).toBe(tag.toUpperCase());
    });

    it.each(['8', '9', '10', '11', '12', '13', 'xs', 'sm'] as const)('renders size="%s"', (size) => {
        render(<Eyebrow size={size}>Size {size}</Eyebrow>);
        const el = screen.getByText(`Size ${size}`);
        expect(el.className).toMatch(size === 'xs' || size === 'sm' ? new RegExp(`text-${size}\\b`) : new RegExp(`text-\\[${size}px\\]`));
    });

    it.each(['0.06', '0.1', '0.12', '0.14', '0.16', '0.18', '0.2'] as const)('renders tracking="%s"', (tracking) => {
        render(<Eyebrow tracking={tracking}>Tracking {tracking}</Eyebrow>);
        expect(screen.getByText(`Tracking ${tracking}`).className).toContain(`tracking-[${tracking}em]`);
    });

    it('renders weight="semibold"', () => {
        render(<Eyebrow weight="semibold">Semibold</Eyebrow>);
        const el = screen.getByText('Semibold');
        expect(el.className).toMatch(/font-semibold/);
        expect(el.className).not.toMatch(/font-bold/);
    });

    it('renders weight="none" without a font-weight class', () => {
        render(<Eyebrow weight="none">No weight</Eyebrow>);
        const el = screen.getByText('No weight');
        expect(el.className).not.toMatch(/font-(bold|semibold)/);
    });

    it.each([
        ['ink-2', 'text-ink-2'],
        ['ink-3', 'text-ink-3'],
        ['horizon', 'text-horizon'],
        ['horizon-deep', 'text-horizon-deep'],
        ['ink-on-sky', 'text-ink-on-sky'],
        ['cream', 'text-cream'],
    ] as const)('renders tone="%s"', (tone, expected) => {
        render(<Eyebrow tone={tone}>Tone {tone}</Eyebrow>);
        expect(screen.getByText(`Tone ${tone}`).className).toContain(expected);
    });

    it('omits a color class when tone is unset, so className can supply a one-off color', () => {
        render(<Eyebrow className="text-cream/60">Custom color</Eyebrow>);
        const el = screen.getByText('Custom color');
        expect(el.className).toContain('text-cream/60');
        expect(el.className).not.toMatch(/text-ink|text-horizon(?!\/)/);
    });

    it('lets className override a variant class via tailwind-merge', () => {
        render(
            <Eyebrow size="11" tracking="0.1" tone="ink-2" className="text-[13px] tracking-[0.2em]">
                Overridden
            </Eyebrow>,
        );
        const el = screen.getByText('Overridden');
        expect(el.className).toContain('text-[13px]');
        expect(el.className).not.toContain('text-[11px]');
        expect(el.className).toContain('tracking-[0.2em]');
        expect(el.className).not.toContain('tracking-[0.1em]');
    });
});
