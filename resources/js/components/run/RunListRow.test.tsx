import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActivityDetail, RunCard } from '@/types/inertia';

import RunListRow from './RunListRow';

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 1,
        activity_id: 99,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00:00',
        distance: 10000,
        elapsed_time: 3600,
        average_heartrate: 150,
        trimp_edwards: 70,
        ...overrides,
    };
}

function runCard(overrides: Partial<RunCard> = {}): RunCard {
    return {
        id: 1,
        activity_id: 99,
        rarity: 'legendary',
        special_move: 'Dawn Sprint',
        badges: ['early_bird'],
        ...overrides,
    };
}

function moodDot() {
    // The leading mood indicator is the row's only aria-hidden <span>.
    return document.querySelector('span[aria-hidden]');
}

describe('RunListRow', () => {
    it('renders activity name + distance', () => {
        render(<RunListRow detail={detail()} />);
        expect(screen.getByText('Morning Run')).toBeInTheDocument();
        expect(screen.getByText('· 10.00 km')).toBeInTheDocument();
    });

    it('renders the formatted elapsed_time', () => {
        render(<RunListRow detail={detail({ elapsed_time: 2054 })} />);
        expect(screen.getByText('34:14')).toBeInTheDocument();
    });

    it('falls back to "Run" when name is null', () => {
        render(<RunListRow detail={detail({ name: null })} />);
        expect(screen.getByText('Run')).toBeInTheDocument();
    });

    it('links to /activities/{activity_id}', () => {
        render(<RunListRow detail={detail({ activity_id: 7 })} />);
        expect(screen.getByRole('link').getAttribute('href')).toBe(
            '/activities/7',
        );
    });

    it('renders an em-dash placeholder when numeric fields are null', () => {
        render(
            <RunListRow
                detail={detail({
                    distance: null,
                    elapsed_time: null,
                    average_heartrate: null,
                })}
            />,
        );
        expect(screen.getByText('· — km')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBe(2);
        expect(screen.getByText('— bpm')).toBeInTheDocument();
    });

    it('derives a mood from TRIMP when none is provided', () => {
        // TRIMP=70 (default fixture) falls in the `blazing` aerobic bucket.
        render(<RunListRow detail={detail()} />);
        expect(moodDot()).toHaveClass('bg-mood-blazing');
    });

    it('uses passed mood when provided (overrides derivation)', () => {
        // TRIMP=70 would derive `blazing`, but the explicit `mood` prop wins.
        render(<RunListRow detail={detail()} mood="chill" />);
        expect(moodDot()).toHaveClass('bg-mood-chill');
    });

    it('derives gassed for a crushing TRIMP', () => {
        render(<RunListRow detail={detail({ trimp_edwards: 220 })} />);
        expect(moodDot()).toHaveClass('bg-mood-gassed');
    });

    it('a post-run note overrides the derived mood too', () => {
        render(
            <RunListRow
                detail={detail()}
                note={{ oneline: 'note', mood: 'wobbly' }}
            />,
        );
        expect(moodDot()).toHaveClass('bg-mood-wobbly');
    });

    it('renders **bold** markers in the note as <strong>', () => {
        render(
            <RunListRow
                detail={detail()}
                note={{ oneline: 'also got a **PR**', mood: 'blazing' }}
            />,
        );
        const strong = screen.getByText('PR');
        expect(strong.tagName).toBe('STRONG');
    });

    it('shows the as-recorded start time next to the date', () => {
        render(
            <RunListRow
                detail={detail({ start_date_local: '2026-05-10T07:00:00' })}
            />,
        );
        expect(screen.getByText(/· 07:00$/)).toBeInTheDocument();
    });

    it('renders the literal wall-clock time even when serialized with a UTC Z (no zone shift)', () => {
        // Laravel sends the naive cast as `...Z`; the time must stay 06:52, not
        // shift to the viewer/test-runner timezone.
        render(
            <RunListRow
                detail={detail({
                    start_date_local: '2026-06-09T06:52:54.000000Z',
                })}
            />,
        );
        expect(screen.getByText(/· 06:52$/)).toBeInTheDocument();
    });

    it('omits the time when start_date_local has no time component', () => {
        render(
            <RunListRow detail={detail({ start_date_local: '2026-05-10' })} />,
        );
        expect(screen.queryByText(/·\s*\d{2}:\d{2}$/)).not.toBeInTheDocument();
    });

    it('shows a rarity-coloured sparkle when a run_card is present', () => {
        render(<RunListRow detail={detail()} runCard={runCard()} />);
        const sparkle = document.querySelector(
            '[aria-label="legendary kartu"]',
        );
        expect(sparkle).toHaveClass('text-rarity-legendary-ink');
    });

    it('shows no sparkle when run_card is absent', () => {
        render(<RunListRow detail={detail()} runCard={null} />);
        expect(
            document.querySelector('[aria-label$="kartu"]'),
        ).not.toBeInTheDocument();
    });
});
