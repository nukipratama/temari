import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SectionLabel from './SectionLabel';

describe('SectionLabel', () => {
    it('renders children + trailing rule', () => {
        const { container } = render(<SectionLabel>Hari ini</SectionLabel>);
        expect(screen.getByText('Hari ini')).toBeInTheDocument();
        // label span + divider span
        expect(container.querySelectorAll('span').length).toBe(2);
        expect(container.firstElementChild).toHaveClass('text-label-small');
    });

    it('switches to the muted ink-on-sky text on sky', () => {
        const { container } = render(<SectionLabel onSky>x</SectionLabel>);
        expect(container.firstElementChild).toHaveClass('text-ink-on-sky');
    });

    it('renders a leading dot instead of the divider when dot is set', () => {
        const { container } = render(<SectionLabel dot>Kondisi</SectionLabel>);
        const dot = container.querySelector('[aria-hidden]');
        expect(dot).not.toBeNull();
        expect(dot).toHaveClass('rounded-full');
        expect(dot).toHaveClass('bg-ink-3');
        // no trailing divider (flex-1 rule)
        expect(container.querySelector('.flex-1')).toBeNull();
        // dot eyebrow runs at the micro tier
        expect(container.firstElementChild).toHaveClass('text-label-micro');
    });

    it('colors the dot via dotClass', () => {
        const { container } = render(
            <SectionLabel dot dotClass="bg-horizon">
                Vibe
            </SectionLabel>,
        );
        expect(container.querySelector('[aria-hidden]')).toHaveClass('bg-horizon');
    });
});
