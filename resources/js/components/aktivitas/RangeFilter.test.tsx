import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import RangeFilter from './RangeFilter';

describe('RangeFilter', () => {
    it('renders all range chips and marks the active one pressed', () => {
        render(<RangeFilter active="12w" />);
        expect(screen.getByRole('button', { name: '8 minggu', pressed: false })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '12 minggu', pressed: true })).toBeInTheDocument();
    });

    it('fires an Inertia GET when an inactive chip is clicked', () => {
        const spy = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<RangeFilter active="8w" />);
        fireEvent.click(screen.getByRole('button', { name: '6 bulan' }));
        expect(spy).toHaveBeenCalledWith(
            '/aktivitas',
            { range: '6m' },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
        spy.mockRestore();
    });

    it('does not fire when the active chip is re-clicked', () => {
        const spy = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<RangeFilter active="8w" />);
        fireEvent.click(screen.getByRole('button', { name: '8 minggu' }));
        expect(spy).not.toHaveBeenCalled();
        spy.mockRestore();
    });
});
