import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import InboxSkeleton from './InboxSkeleton';

describe('InboxSkeleton', () => {
    it('keeps the page eyebrow over loading shapes', () => {
        render(<InboxSkeleton />);

        expect(screen.getByText('Inbox')).toBeInTheDocument();
        expect(
            screen.getAllByRole('status', { name: 'Loading' }).length,
        ).toBeGreaterThan(0);
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<InboxSkeleton />);

        expect(container.querySelector('.reveal')).toBeNull();
    });
});
