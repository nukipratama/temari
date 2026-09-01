import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { PastYouComparison, PastYouTrend } from '@/types/inertia';

import EvidenceList from './EvidenceList';

function pair(
    activityId: number,
    paceDelta: number,
    hrDelta: number | null,
    direction: PastYouComparison['direction'],
): PastYouComparison {
    return {
        direction,
        days_apart: 90,
        similarity: 0.9,
        pace_delta_sec: paceDelta,
        hr_delta_bpm: hrDelta,
        current: {
            activity_id: activityId,
            date: '2026-06-12',
            km: 8.2,
            pace_sec_per_km: 420,
            average_heartrate: 152,
            elevation_gain_m: 40,
            ingest_state: 'summary',
        },
        past: {
            activity_id: activityId + 100,
            date: '2026-03-14',
            km: 8.2,
            pace_sec_per_km: 420 + paceDelta,
            average_heartrate: 158,
            elevation_gain_m: 40,
            ingest_state: 'summary',
        },
    };
}

function trend(comparisons: PastYouComparison[]): PastYouTrend {
    return {
        verdict: 'improving',
        window_days: 42,
        comparison_count: comparisons.length,
        comparisons,
        mean_pace_delta_sec: 10,
        mean_hr_delta_bpm: -5,
        fitness_delta_ctl: null,
        pace_consistency_now: null,
        pace_consistency_then: null,
        relative_effort_band: null,
    };
}

describe('EvidenceList', () => {
    it('shows each pair as a before, after and delta', () => {
        render(<EvidenceList trend={trend([pair(2, 12, -6, 'better')])} />);

        expect(screen.getByText('7:12')).toBeInTheDocument();
        expect(screen.getByText('7:00')).toBeInTheDocument();
        expect(screen.getByText('-12 s/km')).toBeInTheDocument();
    });

    it('names what made the pair comparable', () => {
        render(<EvidenceList trend={trend([pair(2, 12, -6, 'better')])} />);

        expect(screen.getByText('8.2 km · pace vs mar 14')).toBeInTheDocument();
    });

    it('links each row to the run it was measured on', () => {
        render(
            <EvidenceList
                trend={trend([
                    pair(2, 12, -6, 'better'),
                    pair(3, 8, -4, 'better'),
                ])}
            />,
        );

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/activities/2');
        expect(links[1]).toHaveAttribute('href', '/activities/3');
    });

    it('shows heart rate on a row whose pace came back flat', () => {
        render(<EvidenceList trend={trend([pair(2, 1, -7, 'better')])} />);

        expect(screen.getByText('158')).toBeInTheDocument();
        expect(screen.getByText('152')).toBeInTheDocument();
        expect(screen.getByText('-7 bpm')).toBeInTheDocument();
    });

    it('reads a genuinely flat pair as holding', () => {
        render(<EvidenceList trend={trend([pair(2, 2, 1, 'flat')])} />);

        expect(screen.getByText('holding')).toBeInTheDocument();
    });

    it('renders nothing when the window matched no pairs', () => {
        const { container } = render(<EvidenceList trend={trend([])} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('describes the whole row to assistive tech', () => {
        render(<EvidenceList trend={trend([pair(2, 12, -6, 'better')])} />);

        expect(
            screen.getByRole('link', {
                name: '8.2 km · pace vs mar 14, 7:12 to 7:00, -12 s/km',
            }),
        ).toBeInTheDocument();
    });
});
