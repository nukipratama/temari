import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RunShowSkeleton from './RunShowSkeleton';

describe('RunShowSkeleton', () => {
    it('keeps the page eyebrow over loading shapes', () => {
        render(<RunShowSkeleton />);

        expect(screen.getByText('Activity')).toBeInTheDocument();
        expect(
            screen.getAllByRole('status', { name: 'Loading' }).length,
        ).toBeGreaterThan(0);
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<RunShowSkeleton />);

        expect(container.querySelector('.reveal')).toBeNull();
    });
});
