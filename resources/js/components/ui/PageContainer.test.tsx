import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PageContainer from './PageContainer';

describe('PageContainer', () => {
    it('centers + caps content width, easing the cap at 2xl', () => {
        const { container } = render(<PageContainer>body</PageContainer>);
        const root = container.firstChild as HTMLElement;
        expect(root).toHaveClass(/mx-auto/);
        expect(root).toHaveClass(/max-w-page/);
        expect(root).toHaveClass(/2xl:max-w-page-2xl/);
    });

    it('renders its children', () => {
        render(<PageContainer>hello</PageContainer>);
        expect(screen.getByText('hello')).toBeInTheDocument();
    });

    it('merges caller className', () => {
        const { container } = render(<PageContainer className="pb-24">x</PageContainer>);
        expect(container.firstChild).toHaveClass(/pb-24/);
    });

    it('renders a plain div (no motion) when static', () => {
        const { container } = render(<PageContainer static>x</PageContainer>);
        expect(container.firstChild).toHaveClass(/max-w-page/);
    });
});
