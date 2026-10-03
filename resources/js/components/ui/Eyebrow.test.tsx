import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Eyebrow from './Eyebrow';

describe('Eyebrow', () => {
    it.each([
        ['micro', 'text-label-micro'],
        ['small', 'text-label-small'],
        ['hero', 'text-label-hero'],
    ] as const)('renders token="%s"', (token, expected) => {
        render(<Eyebrow token={token}>Token {token}</Eyebrow>);
        expect(screen.getByText(`Token ${token}`)).toHaveClass(expected);
    });

    it.each(['div', 'span', 'h3', 'dt', 'footer'] as const)(
        'renders as="%s"',
        (tag) => {
            render(
                <Eyebrow token="micro" as={tag}>
                    Label {tag}
                </Eyebrow>,
            );
            expect(screen.getByText(`Label ${tag}`).tagName).toBe(
                tag.toUpperCase(),
            );
        },
    );

    it('defaults to a div when as is unset', () => {
        render(<Eyebrow token="micro">Label</Eyebrow>);
        expect(screen.getByText('Label').tagName).toBe('DIV');
    });

    it.each([
        ['ink-2', 'text-text-2'],
        ['ink-3', 'text-text-3'],
        ['horizon', 'text-horizon'],
        ['horizon-ink', 'text-horizon-ink'],
        ['ink-on-sky', 'text-ink-on-sky'],
        ['cream', 'text-cream'],
    ] as const)('renders tone="%s"', (tone, expected) => {
        render(
            <Eyebrow token="micro" tone={tone}>
                Tone {tone}
            </Eyebrow>,
        );
        expect(screen.getByText(`Tone ${tone}`)).toHaveClass(expected);
    });

    it('omits a color class when tone is unset, so className can supply a one-off color', () => {
        render(
            <Eyebrow token="micro" className="text-cream/60">
                Custom color
            </Eyebrow>,
        );
        const el = screen.getByText('Custom color');
        expect(el.className).toContain('text-cream/60');
        expect(el.className).not.toMatch(/text-foreground|text-horizon(?!\/)/);
    });

    it('lets className override the token size via tailwind-merge', () => {
        render(
            <Eyebrow
                token="micro"
                tone="ink-2"
                className="text-[0.7rem] tracking-[0.14em] font-normal"
            >
                Overridden
            </Eyebrow>,
        );
        const el = screen.getByText('Overridden');
        expect(el.className).toContain('text-[0.7rem]');
        expect(el.className).not.toContain('text-label-micro');
    });

    it('draws a trailing rule after the label when rule is set', () => {
        const { container } = render(
            <Eyebrow token="small" tone="ink-2" rule>
                Appearance
            </Eyebrow>,
        );
        const root = container.firstElementChild as HTMLElement;

        expect(root).toHaveClass('flex', 'items-center', 'text-label-small');
        expect(root.lastElementChild).toHaveAttribute('aria-hidden');
        expect(root.lastElementChild).toHaveClass(
            'h-px',
            'flex-1',
            'bg-current',
        );
        expect(screen.getByText('Appearance').tagName).toBe('SPAN');
    });

    it('keeps the rule on the sky tone for a label on a dark panel', () => {
        const { container } = render(
            <Eyebrow token="small" tone="ink-on-sky" rule>
                While you wait
            </Eyebrow>,
        );
        const root = container.firstElementChild as HTMLElement;

        expect(root).toHaveClass('text-ink-on-sky');
        expect(root.lastElementChild).toHaveClass('bg-current');
    });

    it('renders no rule by default', () => {
        const { container } = render(<Eyebrow token="small">Plain</Eyebrow>);

        expect(container.querySelector('[aria-hidden]')).toBeNull();
    });
});
