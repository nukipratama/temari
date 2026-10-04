import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

import LastOpen from './LastOpen';

describe('LastOpen', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-30T10:00:00Z'));
        setMockPage({ today: '2026-09-30' });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the last active day of an active athlete, with no away badge', () => {
        render(
            <LastOpen
                lastSeenAt="2026-09-27T15:00:00+07:00"
                away={false}
                isDemo={false}
            />,
        );

        expect(screen.getByText('last active 3 days ago')).toBeInTheDocument();
        expect(screen.queryByText(/away:/)).not.toBeInTheDocument();
    });

    it('shows a stamp from today as active today with its first-open time in the app timezone', () => {
        render(
            <LastOpen
                lastSeenAt="2026-09-30T05:03:12+07:00"
                away={false}
                isDemo={false}
            />,
        );

        expect(
            screen.getByText('active today · first open 05:03'),
        ).toBeInTheDocument();
    });

    it('marks an away athlete as paused and says their next open catches up', () => {
        render(
            <LastOpen lastSeenAt="2026-09-01T08:00:00Z" away isDemo={false} />,
        );

        expect(
            screen.getByText('away: scheduled narration paused'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/next open re-includes them/),
        ).toBeInTheDocument();
    });

    it('says never for an athlete who has not opened the app', () => {
        render(<LastOpen lastSeenAt={null} away isDemo={false} />);

        expect(screen.getByText('never opened')).toBeInTheDocument();
        expect(
            screen.getByText('away: scheduled narration paused'),
        ).toBeInTheDocument();
    });

    it('shows the demo account as never stamped, without an away badge', () => {
        render(<LastOpen lastSeenAt={null} away={false} isDemo />);

        expect(screen.getByText('demo (never stamped)')).toBeInTheDocument();
        expect(screen.queryByText(/away:/)).not.toBeInTheDocument();
        expect(screen.queryByText('never opened')).not.toBeInTheDocument();
    });
});
