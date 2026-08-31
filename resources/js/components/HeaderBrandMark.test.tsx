import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HeaderBrandMark from './HeaderBrandMark';

describe('HeaderBrandMark', () => {
    it('renders the lowercase wordmark', () => {
        render(<HeaderBrandMark />);
        expect(screen.getByText('temari')).toBeInTheDocument();
    });

    it('applies the provided className', () => {
        const { container } = render(<HeaderBrandMark className="mb-10" />);
        expect(container.firstChild).toHaveClass('mb-10');
    });

    it('applies wordmarkClassName to the wordmark span', () => {
        render(
            <HeaderBrandMark wordmarkClassName="hidden min-[350px]:inline" />,
        );
        expect(screen.getByText('temari')).toHaveClass('hidden');
    });
});
