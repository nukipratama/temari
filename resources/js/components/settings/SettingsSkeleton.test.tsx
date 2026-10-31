import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SettingsSkeleton from './SettingsSkeleton';

describe('SettingsSkeleton', () => {
    it('keeps the page eyebrow over loading shapes', () => {
        render(<SettingsSkeleton />);

        expect(screen.getByText('Settings')).toBeInTheDocument();
        expect(
            screen.getAllByRole('status', { name: 'Loading' }).length,
        ).toBeGreaterThan(0);
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<SettingsSkeleton />);

        expect(container.querySelector('.reveal')).toBeNull();
    });
});
