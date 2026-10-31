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

    it.each([
        ['no hint', undefined],
        ['an unsaved effort', false],
    ])('holds the effort picker its page shows for %s', (_, effortSaved) => {
        const { container } = render(
            <RunShowSkeleton effortSaved={effortSaved} />,
        );

        expect(container.querySelector('section .skeleton')).not.toBeNull();
    });

    it('leaves the effort picker out when the run has a saved effort', () => {
        const { container } = render(<RunShowSkeleton effortSaved />);

        expect(container.querySelector('section .skeleton')).toBeNull();
    });
});
