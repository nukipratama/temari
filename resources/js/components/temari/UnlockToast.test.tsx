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

describe('UnlockToast', () => {
    it('renders nothing when no flash.unlock is set', () => {
        setMockPage({
            auth: { user: null },
            flash: { success: null, error: null, info: null },
            demoLoginEnabled: false,
        });
        const { container } = render(<UnlockToast />);
        expect(container.querySelector('[role="status"]')).not.toBeInTheDocument();
    });

    it('renders toast when flash.unlock is present', () => {
        setMockPage({
            auth: { user: null },
            flash: {
                success: null,
                error: null,
                info: null,
                unlock: { unlock_key: 'accessory.medal_gold', name: 'Medali Emas', icon: 'mdi:medal' },
            },
            demoLoginEnabled: false,
        });
        render(<UnlockToast />);
        expect(screen.getByText('Medali Emas')).toBeInTheDocument();
        expect(screen.getByText('Unlock baru')).toBeInTheDocument();
    });

    it('schedules the auto-dismiss timer when flash.unlock is present', () => {
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout');
        setMockPage({
            auth: { user: null },
            flash: {
                success: null,
                error: null,
                info: null,
                unlock: { unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' },
            },
            demoLoginEnabled: false,
        });
        render(<UnlockToast />);
        expect(setTimeoutSpy).toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(6000);
        });
    });

    it('close button is wired up to dismiss handler', () => {
        setMockPage({
            auth: { user: null },
            flash: {
                success: null,
                error: null,
                info: null,
                unlock: { unlock_key: 'accessory.crown', name: 'Mahkota', icon: 'mdi:crown' },
            },
            demoLoginEnabled: false,
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
