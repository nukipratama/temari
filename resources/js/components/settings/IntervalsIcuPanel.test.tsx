import { router } from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { showToast } from '@/hooks/useToast';
import { makeUser, setMockPage } from '@/test/setup';

vi.mock('@/hooks/useToast', () => ({ showToast: vi.fn() }));

import IntervalsIcuPanel, {
    INTERVALS_ICU_DEMO_BLOCKED,
    type IntervalsIcuPayload,
} from './IntervalsIcuPanel';

const notConnected: IntervalsIcuPayload = {
    connected: false,
    athlete_id: null,
    broken: false,
};

const healthy: IntervalsIcuPayload = {
    connected: true,
    athlete_id: 'i123456',
    broken: false,
};

beforeEach(() => {
    setMockPage({ auth: { user: makeUser() } });
    vi.mocked(router.delete).mockClear();
    vi.mocked(showToast).mockClear();
});

function openRow() {
    fireEvent.click(screen.getByRole('button', { name: /intervals\.icu/ }));
}

describe('IntervalsIcuPanel', () => {
    it('keeps the connect form folded until the row is opened', async () => {
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);

        const row = screen.getByRole('button', { name: /intervals\.icu/ });
        expect(row).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.getByText('send your next run to your watch.'),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument();

        openRow();

        expect(row).toHaveAttribute('aria-expanded', 'true');
        expect(await screen.findByLabelText('athlete ID')).toBeInTheDocument();
    });

    it('shows a healthy connection as a static row with a disconnect beneath it', () => {
        render(<IntervalsIcuPanel intervalsIcu={healthy} />);

        expect(
            screen.getByText('connected · athlete i123456'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /intervals\.icu/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /disconnect/i }),
        ).toBeInTheDocument();
    });

    it('asks before disconnecting, and cancel sends nothing', async () => {
        render(<IntervalsIcuPanel intervalsIcu={healthy} />);

        fireEvent.click(screen.getByRole('button', { name: /disconnect/i }));

        expect(
            await screen.findByRole('dialog', {
                name: 'disconnect intervals.icu?',
            }),
        ).toBeInTheDocument();
        expect(router.delete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'not now' }));
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('disconnects once on confirm', async () => {
        render(<IntervalsIcuPanel intervalsIcu={healthy} />);

        fireEvent.click(screen.getByRole('button', { name: /disconnect/i }));
        const dialog = await screen.findByRole('dialog', {
            name: 'disconnect intervals.icu?',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'disconnect' }),
        );

        expect(router.delete).toHaveBeenCalledOnce();
        expect(router.delete).toHaveBeenCalledWith('/settings/intervals-icu', {
            preserveScroll: true,
            onSuccess: expect.any(Function),
        });
        expect(showToast).not.toHaveBeenCalled();

        vi.mocked(router.delete).mock.calls[0]?.[1]?.onSuccess?.({} as never);

        expect(showToast).toHaveBeenCalledExactlyOnceWith(
            'intervals.icu disconnected',
        );
    });

    it('offers to reconnect a broken key from the same form', async () => {
        render(
            <IntervalsIcuPanel
                intervalsIcu={{
                    connected: true,
                    athlete_id: 'i123456',
                    broken: true,
                }}
            />,
        );

        expect(
            screen.getByText(
                'your key stopped working · reconnect to keep sending runs.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /disconnect/i }),
        ).toBeInTheDocument();

        openRow();

        expect(await screen.findByLabelText(/^API key/)).toBeInTheDocument();
    });

    it('opens the demo notice instead of the form for the demo account', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);

        openRow();

        expect(
            await screen.findByText(INTERVALS_ICU_DEMO_BLOCKED.title),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument();
    });
});
