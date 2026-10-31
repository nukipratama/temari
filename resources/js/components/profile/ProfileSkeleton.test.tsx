import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProfileSkeleton from './ProfileSkeleton';

describe('ProfileSkeleton', () => {
    it('keeps the page eyebrow over loading shapes', () => {
        render(<ProfileSkeleton />);

        expect(screen.getByText('Profile')).toBeInTheDocument();
        expect(
            screen.getAllByRole('status', { name: 'Loading' }).length,
        ).toBeGreaterThan(0);
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<ProfileSkeleton />);

        expect(container.querySelector('.reveal')).toBeNull();
    });
});
