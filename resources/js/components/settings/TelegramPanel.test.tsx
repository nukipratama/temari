import { router } from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { showToast } from '@/hooks/useToast';
import { makeUser, setMockPage } from '@/test/setup';

vi.mock('@/hooks/useToast', () => ({
    showToast: vi.fn(),
    useToast: () => null,
}));

import TelegramPanel from './TelegramPanel';

const connectedTelegram = {
    connected: true,
    username: 'ada_runs',
    connect_url: null,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('TelegramPanel', () => {
    it('shows the Telegram connect link when not connected', () => {
        const telegram = {
            connected: false,
            username: null,
            connect_url: 'https://t.me/temari_bot?start=tok',
        };
        render(
            <TelegramPanel
                telegram={telegram}
                muted={false}
                onMuteChange={vi.fn()}
            />,
        );
        expect(screen.getByRole('link', { name: /Telegram/ })).toHaveAttribute(
            'href',
            'https://t.me/temari_bot?start=tok',
        );
    });

    it('keeps the Telegram connect row for the demo, which gets no link, and opens the demo-blocked modal', async () => {
        setMockPage({
            auth: { user: makeUser({ is_demo: true }) },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <TelegramPanel
                telegram={{
                    connected: false,
                    username: null,
                    connect_url: null,
                }}
                muted={false}
                onMuteChange={vi.fn()}
            />,
        );

        expect(
            screen.queryByText("the Telegram bot isn't configured yet."),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByText('connect it so temari can keep you posted.'),
        );

        expect(
            await screen.findByText("Telegram's taking a break for now"),
        ).toBeInTheDocument();
    });

    it('asks before disconnecting Telegram, and cancel sends nothing', async () => {
        vi.mocked(router.delete).mockReset();
        render(
            <TelegramPanel
                telegram={connectedTelegram}
                muted={false}
                onMuteChange={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('Disconnect'));

        expect(
            await screen.findByRole('dialog', { name: 'disconnect Telegram?' }),
        ).toBeInTheDocument();
        expect(router.delete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'not now' }));
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('disconnects Telegram via one DELETE on confirm', async () => {
        vi.mocked(router.delete).mockReset();
        render(
            <TelegramPanel
                telegram={connectedTelegram}
                muted={false}
                onMuteChange={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('Disconnect'));
        const dialog = await screen.findByRole('dialog', {
            name: 'disconnect Telegram?',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'disconnect' }),
        );

        expect(router.delete).toHaveBeenCalledOnce();
        expect(router.delete).toHaveBeenCalledWith('/profile/telegram', {
            preserveScroll: true,
            onSuccess: expect.any(Function),
        });
        expect(showToast).not.toHaveBeenCalled();

        vi.mocked(router.delete).mock.calls[0]?.[1]?.onSuccess?.({} as never);

        expect(showToast).toHaveBeenCalledExactlyOnceWith(
            'Telegram disconnected',
        );
    });
});
