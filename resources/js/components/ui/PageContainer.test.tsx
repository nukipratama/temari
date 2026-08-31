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

    it("caps at the prototype's 760px column above it, with no other step", () => {
        const { container } = render(<PageContainer>x</PageContainer>);
        const root = container.firstChild as HTMLElement;
        expect(root).toHaveClass('min-[900px]:max-w-[760px]');
        expect(root).toHaveClass('min-[900px]:px-6');
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
