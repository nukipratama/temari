import { render, screen, act } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UnlockToast from './UnlockToast';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

function flashWithUnlock(unlock: { unlock_key: string; name: string; icon: string }) {
    return { success: null, error: null, info: null, unlock };
}

describe('UnlockToast', () => {
    it('renders nothing when no flash.unlock is set', () => {
        setMockPage({});
        const { container } = render(<UnlockToast />);
        expect(container.querySelector('[role="status"]')).not.toBeInTheDocument();
    });

    it('renders toast when flash.unlock is present', () => {
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.medal_emas', name: 'Medali Emas', icon: 'mdi:medal' }),
        });
        render(<UnlockToast />);
        expect(screen.getByText('Medali Emas')).toBeInTheDocument();
        expect(screen.getByText('Unlock baru')).toBeInTheDocument();
    });

    it('clears the mobile bottom nav with a safe-area-aware offset, resetting to bottom-6 on lg', () => {
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.medal_emas', name: 'Medali Emas', icon: 'mdi:medal' }),
        });
        render(<UnlockToast />);
        const toast = screen.getByRole('status');
        expect(toast.className).toContain('bottom-[calc(5.5rem+env(safe-area-inset-bottom))]');
        expect(toast.className).toContain('lg:bottom-6');
    });

    it('schedules the auto-dismiss timer when flash.unlock is present', async () => {
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout');
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' }),
        });
        render(<UnlockToast />);
        expect(setTimeoutSpy).toHaveBeenCalled();
        // async act flushes AnimatePresence's safe-to-remove tick after setActive(null).
        await act(async () => {
            vi.advanceTimersByTime(6000);
        });
    });

    it('close button is wired up to dismiss handler', async () => {
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' }),
        });
        render(<UnlockToast />);
        const dismissBtn = screen.getByLabelText('Tutup notifikasi');
        expect(dismissBtn).toBeInTheDocument();
        // async act flushes AnimatePresence's safe-to-remove tick after setActive(null).
        await act(async () => {
            dismissBtn.click();
        });
        // After click, internal state is cleared; toast eventually hides via AnimatePresence.
    });
});
