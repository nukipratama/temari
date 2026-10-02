import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import LastOpen from './LastOpen';

describe('LastOpen', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-30T10:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows how long ago an active athlete opened the app, with no away badge', () => {
        render(
            <LastOpen
                lastSeenAt="2026-09-27T08:00:00Z"
                away={false}
                isDemo={false}
            />,
        );

        expect(screen.getByText('opened 3 days ago')).toBeInTheDocument();
        expect(screen.queryByText(/away:/)).not.toBeInTheDocument();
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
