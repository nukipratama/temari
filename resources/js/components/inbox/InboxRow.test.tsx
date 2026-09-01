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
    distance_m: null,
    moving_time_s: null,
    ...overrides,
});

function renderRow(overrides: Partial<InboxItem> = {}, props = {}) {
    const onOpen = vi.fn();
    render(
        <InboxRow
            item={item(overrides)}
            read={false}
            focused={false}
            onOpen={onOpen}
            {...props}
        />,
    );
    return { onOpen };
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
            <InboxRow item={item()} read focused={false} onOpen={vi.fn()} />,
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

    it('offers no action for a row with nothing to open', () => {
        renderRow();

        expect(screen.queryByText('Open')).not.toBeInTheDocument();
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
            <InboxRow item={item()} read focused onOpen={vi.fn()} />,
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

    it('shows the rarity badge instead of the kind label for a rated unlock', () => {
        renderRow({ kind: 'unlock', rarity: 'legendary' });

        expect(screen.getByText('Legendary Unlock')).toBeInTheDocument();
        expect(screen.queryByText('Unlock')).not.toBeInTheDocument();
    });

    it('falls back to the plain kind label for an unlock with no rarity', () => {
        renderRow({ kind: 'unlock', rarity: null });

        expect(screen.getByText('Unlock')).toBeInTheDocument();
    });

    it('draws distance and pace chips on a post-run row', () => {
        renderRow({ kind: 'post_run', distance_m: 6400, moving_time_s: 1868 });

        expect(screen.getByText('6.4 km')).toBeInTheDocument();
        expect(screen.getByText('Distance')).toBeInTheDocument();
        expect(screen.getByText('4:52/km')).toBeInTheDocument();
        expect(screen.getByText('Pace')).toBeInTheDocument();
    });

    it('drops the pace chip when the run carries no moving time', () => {
        renderRow({ kind: 'post_run', distance_m: 6400, moving_time_s: null });

        expect(screen.getByText('6.4 km')).toBeInTheDocument();
        expect(screen.queryByText('Pace')).not.toBeInTheDocument();
    });

    it('draws no stat chips on a row that is not a run', () => {
        renderRow({
            kind: 'weekly_recap',
            distance_m: 6400,
            moving_time_s: 1868,
        });

        expect(screen.queryByText('Distance')).not.toBeInTheDocument();
    });

    it('toggles the timestamp between relative and absolute on tap', async () => {
        renderRow({ created_at: '2026-08-13T07:30:00+07:00' });

        const toggle = screen.getByRole('button');
        const relativeText = toggle.textContent;

        await userEvent.click(toggle);

        expect(toggle.textContent).not.toBe(relativeText);
        expect(toggle.textContent).toMatch(/Aug 1[23] · \d{2}:\d{2}/);

        await userEvent.click(toggle);

        expect(toggle.textContent).toBe(relativeText);
    });
});
