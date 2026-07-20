import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ActiveFilterChips from './ActiveFilterChips';

describe('ActiveFilterChips', () => {
    // The row must cost no vertical space in the common unfiltered case.
    it('renders nothing when no filter is active', () => {
        const { container } = render(<ActiveFilterChips chips={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders one removable chip per active filter', () => {
        render(
            <ActiveFilterChips
                chips={[
                    { key: 'a', label: 'Enteng', onRemove: vi.fn() },
                    { key: 'b', label: 'Half ke atas', onRemove: vi.fn() },
                ]}
            />,
        );

        expect(screen.getByRole('button', { name: 'Hapus filter Enteng' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hapus filter Half ke atas' })).toBeInTheDocument();
    });

    it('removes only the chip that was tapped', () => {
        const removeA = vi.fn();
        const removeB = vi.fn();
        render(
            <ActiveFilterChips
                chips={[
                    { key: 'a', label: 'Enteng', onRemove: removeA },
                    { key: 'b', label: 'Half ke atas', onRemove: removeB },
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Hapus filter Enteng' }));

        expect(removeA).toHaveBeenCalledOnce();
        expect(removeB).not.toHaveBeenCalled();
    });

    // Clearing everything is only worth its own control once there is more than
    // one thing to clear.
    it('offers clear-all only beyond a single chip', () => {
        const onClearAll = vi.fn();
        const { rerender } = render(
            <ActiveFilterChips chips={[{ key: 'a', label: 'Enteng', onRemove: vi.fn() }]} onClearAll={onClearAll} />,
        );
        expect(screen.queryByRole('button', { name: 'Hapus semua' })).not.toBeInTheDocument();

        rerender(
            <ActiveFilterChips
                chips={[
                    { key: 'a', label: 'Enteng', onRemove: vi.fn() },
                    { key: 'b', label: 'Nyala', onRemove: vi.fn() },
                ]}
                onClearAll={onClearAll}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Hapus semua' }));
        expect(onClearAll).toHaveBeenCalledOnce();
    });

    it('omits clear-all when no handler is given', () => {
        render(
            <ActiveFilterChips
                chips={[
                    { key: 'a', label: 'Enteng', onRemove: vi.fn() },
                    { key: 'b', label: 'Nyala', onRemove: vi.fn() },
                ]}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Hapus semua' })).not.toBeInTheDocument();
    });
});
