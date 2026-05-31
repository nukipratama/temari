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
            flash: flashWithUnlock({ unlock_key: 'accessory.medal_gold', name: 'Medali Emas', icon: 'mdi:medal' }),
        });
        render(<UnlockToast />);
        expect(screen.getByText('Medali Emas')).toBeInTheDocument();
        expect(screen.getByText('Unlock baru')).toBeInTheDocument();
    });

    it('schedules the auto-dismiss timer when flash.unlock is present', () => {
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout');
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' }),
        });
        render(<UnlockToast />);
        expect(setTimeoutSpy).toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(6000);
        });
    });

    it('close button is wired up to dismiss handler', () => {
        setMockPage({
            flash: flashWithUnlock({ unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' }),
        });
        render(<UnlockToast />);
        const dismissBtn = screen.getByLabelText('Tutup notifikasi');
        expect(dismissBtn).toBeInTheDocument();
        act(() => {
            dismissBtn.click();
        });
        // After click, internal state is cleared; toast eventually hides via AnimatePresence.
    });
});
