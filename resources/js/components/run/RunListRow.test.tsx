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

    it('marks itself as the morph source for its run, unnamed until a transition starts', () => {
        render(<RunListRow detail={detail({ activity_id: 7 })} />);
        const link = screen.getByRole('link');
        expect(link.dataset.runMorph).toBe('7');
        expect(link.style.getPropertyValue('view-transition-name')).toBe('');
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

    // The row used to guess a mood from TRIMP when none was stored. That guess
    // disagreed with the backend rule engine, so a row could show one mood and
    // then flip once narration landed. No dot beats a wrong dot.
    it('shows no mood dot when nothing has assigned one yet', () => {
        render(<RunListRow detail={detail()} />);
        expect(moodDot()).toBeNull();
    });

    it('uses the passed mood when provided', () => {
        render(<RunListRow detail={detail()} mood="chill" />);
        expect(moodDot()).toHaveClass('bg-mood-chill');
    });

    it('a post-run note wins over the passed mood', () => {
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
        const sparkle = document.querySelector('[aria-label="legendary card"]');
        expect(sparkle).toHaveClass('text-rarity-legendary-ink');
    });

    it('shows no sparkle when run_card is absent', () => {
        render(<RunListRow detail={detail()} runCard={null} />);
        expect(
            document.querySelector('[aria-label$="card"]'),
        ).not.toBeInTheDocument();
    });

    it("colors the row's leading-edge stripe by effort", () => {
        render(<RunListRow detail={detail({ effort: 'hard' })} />);
        expect(screen.getByRole('link')).toHaveClass('border-ember');
    });

    it('names the effort and the visible mood dot for screen readers', () => {
        render(
            <RunListRow detail={detail({ effort: 'hard' })} mood="blazing" />,
        );
        expect(
            screen.getByRole('link', { name: /hard effort, blazing mood/ }),
        ).toBeInTheDocument();
    });

    it('names the effort without inventing a missing mood', () => {
        render(<RunListRow detail={detail({ effort: 'easy' })} />);
        expect(
            screen.getByRole('link', { name: /easy effort/ }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link')).not.toHaveAccessibleName(/mood/);
    });

    it('falls back to the unknown stripe when the detail carries no effort', () => {
        render(<RunListRow detail={detail({ effort: undefined })} />);
        expect(screen.getByRole('link')).toHaveClass('border-border');
    });

    it('puts the date and time on the metrics line, not beside the title', () => {
        render(<RunListRow detail={detail()} />);
        const time = screen.getByText(/· 07:00$/);
        const when = time.parentElement;
        const metricsLine = screen.getByText('150 bpm').parentElement;
        expect(metricsLine).toContainElement(when);
        expect(when?.className).toContain('text-[0.6875rem]');
        expect(time).toHaveClass('max-[359px]:hidden');
        const titleLine = screen.getByText('Morning Run').parentElement;
        expect(titleLine).not.toContainElement(when);
    });

    it('lets the title own the first line and exposes the full name as a title attribute', () => {
        render(
            <RunListRow detail={detail({ name: 'Morning intervals 2x10' })} />,
        );
        const name = screen.getByText('Morning intervals 2x10');
        expect(name).toHaveAttribute('title', 'Morning intervals 2x10');
        expect(name.className).toContain('truncate');
        expect(name.parentElement?.className).not.toContain('justify-between');
        expect(name.parentElement?.parentElement?.className).not.toContain(
            'justify-between',
        );
    });
});
