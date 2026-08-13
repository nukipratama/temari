import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { InboxItem } from '@/types/inertia';

import InboxRow from './InboxRow';

const item = (overrides: Partial<InboxItem> = {}): InboxItem => ({
    id: 1,
    kind: 'post_run',
    title: 'Your run is in',
    body: 'nice and steady out there.',
    created_at: '2026-08-13T07:30:00+07:00',
    read_at: null,
    url: null,
    run_card_id: null,
    rarity: null,
    unlock: null,
    ...overrides,
});

function renderRow(overrides: Partial<InboxItem> = {}, props = {}) {
    const onReplay = vi.fn();
    const onOpen = vi.fn();
    render(
        <InboxRow
            item={item(overrides)}
            read={false}
            focused={false}
            replaying={false}
            onReplay={onReplay}
            onOpen={onOpen}
            {...props}
        />,
    );
    return { onReplay, onOpen };
}

describe('InboxRow', () => {
    it('renders the kind label, title and body', () => {
        renderRow();

        expect(screen.getByText('Post-run')).toBeInTheDocument();
        expect(screen.getByText('Your run is in')).toBeInTheDocument();
        expect(
            screen.getByText('nice and steady out there.'),
        ).toBeInTheDocument();
    });

    it('renders the created instant as a machine-readable time', () => {
        const { container } = render(
            <InboxRow
                item={item()}
                read
                focused={false}
                replaying={false}
                onReplay={vi.fn()}
                onOpen={vi.fn()}
            />,
        );

        expect(container.querySelector('time')).toHaveAttribute(
            'datetime',
            '2026-08-13T07:30:00+07:00',
        );
    });

    it('flags an unread row', () => {
        renderRow();

        expect(screen.getByLabelText('Unread')).toBeInTheDocument();
    });

    it('drops the unread flag once read', () => {
        renderRow({}, { read: true });

        expect(screen.queryByLabelText('Unread')).not.toBeInTheDocument();
    });

    it('offers no action for a row with nothing to open or replay', () => {
        renderRow();

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByText('Open')).not.toBeInTheDocument();
    });

    it('replays the reveal for a row carrying a card id', async () => {
        const { onReplay } = renderRow({ run_card_id: 9, rarity: 'epic' });

        await userEvent.click(screen.getByText('Replay Reveal'));

        expect(onReplay).toHaveBeenCalledWith(
            expect.objectContaining({ run_card_id: 9 }),
        );
    });

    it('replays the takeover for an unlock row', async () => {
        const { onReplay } = renderRow({
            kind: 'unlock',
            unlock: {
                unlock_key: 'accessory.headband_legendary',
                name: 'Legendary headband',
                icon: 'mdi:hanger',
                is_major: true,
            },
        });

        await userEvent.click(screen.getByText('Replay Unlock'));

        expect(onReplay).toHaveBeenCalledTimes(1);
    });

    it('disables the replay while one is in flight', () => {
        renderRow({ run_card_id: 9 }, { replaying: true });

        expect(screen.getByText('Replaying').closest('button')).toBeDisabled();
    });

    it('opens the deep link and reports it read', async () => {
        const { onOpen } = renderRow({
            kind: 'weekly_recap',
            url: '/activities?week=2026-08-09',
        });

        const link = screen.getByText('Open');
        expect(link).toHaveAttribute('href', '/activities?week=2026-08-09');

        await userEvent.click(link);
        expect(onOpen).toHaveBeenCalledTimes(1);
    });

    it('rings the deep-linked row', () => {
        const { container } = render(
            <InboxRow
                item={item()}
                read
                focused
                replaying={false}
                onReplay={vi.fn()}
                onOpen={vi.fn()}
            />,
        );

        expect(container.firstElementChild?.className).toContain(
            'ring-horizon',
        );
    });

    it.each([
        ['weekly_recap', 'Weekly Recap'],
        ['monthly_recap', 'Monthly Recap'],
        ['streak_reminder', 'Streak'],
        ['unlock', 'Unlock'],
        ['test', 'Test'],
    ] as const)('labels the %s kind', (kind, label) => {
        renderRow({ kind, body: null });

        expect(screen.getByText(label)).toBeInTheDocument();
    });
});
