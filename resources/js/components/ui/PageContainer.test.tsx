import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PageContainer from './PageContainer';

describe('PageContainer', () => {
    it('runs the full mobile column width below the 900px breakpoint', () => {
        const { container } = render(<PageContainer>body</PageContainer>);
        const root = container.firstChild as HTMLElement;
        expect(root).toHaveClass(/mx-auto/);
        expect(root).toHaveClass(/w-full/);
        expect(root).toHaveClass(/px-4/);
        expect(root.className).not.toMatch(/max-w-page/);
    });

    it("takes the prototype's column at 900px and the wide step at 1280px", () => {
        const { container } = render(<PageContainer>x</PageContainer>);
        const root = container.firstChild as HTMLElement;
        expect(root).toHaveClass('min-[900px]:max-w-column');
        expect(root).toHaveClass('min-[1280px]:max-w-column-wide');
        expect(root).toHaveClass('min-[900px]:px-6');
        // Both steps are explicit pixel queries; none of Tailwind's own
        // rem-based breakpoints are used, so the root type step at 1280 cannot
        // shift where the column changes.
        expect(root.className).not.toMatch(/\b(sm|md|lg|xl|2xl):/);
    });

    it('renders its children', () => {
        render(<PageContainer>hello</PageContainer>);
        expect(screen.getByText('hello')).toBeInTheDocument();
    });

    it('merges caller className', () => {
        const { container } = render(
            <PageContainer className="pb-24">x</PageContainer>,
        );
        expect(container.firstChild).toHaveClass(/pb-24/);
    });
});
