import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SectionHeading from './SectionHeading';

describe('SectionHeading', () => {
    it('renders the title in an h2', () => {
        render(<SectionHeading title="Kata Temari" />);
        const h2 = screen.getByRole('heading', {
            level: 2,
            name: 'Kata Temari',
        });
        expect(h2).toBeInTheDocument();
    });

    it('renders the subtitle when provided', () => {
        render(<SectionHeading title="T" subtitle="caption text" />);
        expect(screen.getByText('caption text')).toBeInTheDocument();
    });

    it('renders an icon container when icon prop is set', () => {
        const { container } = render(
            <SectionHeading title="T" icon="mdi:run" />,
        );
        // Icon wrapper is the aria-hidden span sibling of the h2 wrapper
        expect(container.querySelector('[aria-hidden]')).toBeInTheDocument();
    });

    it('omits the icon container when icon prop is empty', () => {
        const { container } = render(<SectionHeading title="T" />);
        expect(container.querySelector('[aria-hidden]')).toBeNull();
    });

    it.each(['brand', 'accent', 'pop', 'neutral'] as const)(
        'renders with %s tone',
        (tone) => {
            render(
                <SectionHeading
                    title={`T-${tone}`}
                    tone={tone}
                    icon="mdi:run"
                />,
            );
            expect(
                screen.getByRole('heading', { level: 2 }),
            ).toBeInTheDocument();
        },
    );
});
