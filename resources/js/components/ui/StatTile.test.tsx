import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatTile from './StatTile';

describe('StatTile', () => {
    it('renders value + label', () => {
        render(<StatTile value="12.5" label="KM" />);
        expect(screen.getByText('12.5')).toBeInTheDocument();
        expect(screen.getByText('KM')).toBeInTheDocument();
    });

    it('renders unit + sub lines when provided', () => {
        render(<StatTile value="100" label="TRIMP" unit="Edwards" sub="Beban sedang" />);
        expect(screen.getByText('Edwards')).toBeInTheDocument();
        expect(screen.getByText('Beban sedang')).toBeInTheDocument();
    });

    it.each(['plain', 'plainSky', 'card', 'cream', 'creamDeep', 'sunken', 'sky'] as const)(
        'renders tone %s',
        (tone) => {
            render(<StatTile value="x" label="y" tone={tone} />);
            expect(screen.getByText('x')).toBeInTheDocument();
        },
    );

    it('applies on-sky text tokens for sky tones', () => {
        const { container } = render(<StatTile value="x" label="y" tone="plainSky" />);
        expect(container.querySelector('.text-ink-on-sky')).not.toBeNull();
        expect(container.querySelector('.text-cream')).not.toBeNull();
    });

    it('scales the value via size variant', () => {
        render(<StatTile value="42" label="y" size="xl" />);
        expect(screen.getByText('42').className).toContain('text-[40px]');
    });

    it('renders a leading dot from dotClass', () => {
        const { container } = render(<StatTile value="x" label="y" dotClass="bg-horizon" />);
        const dot = container.querySelector('[aria-hidden].bg-horizon');
        expect(dot).not.toBeNull();
        expect(dot).toHaveClass('rounded-full');
    });

    it('centers the stack when align=center', () => {
        const { container } = render(<StatTile value="x" label="y" align="center" />);
        expect(container.firstElementChild).toHaveClass('text-center');
    });

    it('lets valueClassName override the value color', () => {
        const { container } = render(
            <StatTile value="x" label="y" valueClassName="text-horizon-deep" />,
        );
        expect(container.querySelector('.text-horizon-deep')).not.toBeNull();
    });

    it('renders an italic quote sub when subVariant=quote', () => {
        const { container } = render(
            <StatTile value="x" label="y" sub="lemes banget" subVariant="quote" />,
        );
        const sub = screen.getByText('lemes banget');
        expect(sub).toHaveClass('italic');
        expect(container.querySelector('.font-display')).not.toBeNull();
    });
});
