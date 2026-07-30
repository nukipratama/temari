import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import DetailTiles from './DetailTiles';
import type { ActivityDetail, StreamSummary } from '@/types/inertia';

const ONE_LEG_CADENCE = 85;

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 11,
        activity_id: 99,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00:00',
        distance: 10000,
        moving_time: 3600,
        average_heartrate: 150,
        trimp_edwards: 70,
        max_heartrate: 175,
        average_cadence: ONE_LEG_CADENCE,
        weather_temp_c: 32,
        ...overrides,
    };
}

const baseSummary: StreamSummary = { decoupling_pct: 4.5 };

function renderTiles(detailOverrides: Partial<ActivityDetail> = {}, summary: StreamSummary = baseSummary) {
    return render(<DetailTiles detail={detail(detailOverrides)} summary={summary} />);
}

describe('DetailTiles', () => {
    it('renders the HR tiles from the detail row', () => {
        renderTiles();
        expect(screen.getByText('AVG HR')).toBeInTheDocument();
        expect(screen.getByText('150')).toBeInTheDocument();
        expect(screen.getByText('MAX HR')).toBeInTheDocument();
        expect(screen.getByText('175')).toBeInTheDocument();
    });

    it('doubles the one-leg average_cadence into a both-legs spm tile', () => {
        renderTiles();
        expect(screen.getByText('CADENCE')).toBeInTheDocument();
        expect(screen.getByText(String(ONE_LEG_CADENCE * 2))).toBeInTheDocument();
        expect(screen.getByText('spm avg')).toBeInTheDocument();
    });

    it('shows TANJAKAN and GAP tiles on a hilly run', () => {
        renderTiles({}, { ...baseSummary, max_grade_pct: 11, gap_pace: '5:20' });
        expect(screen.getByText('TANJAKAN')).toBeInTheDocument();
        expect(screen.getByText('11%')).toBeInTheDocument();
        expect(screen.getByText('GAP')).toBeInTheDocument();
        expect(screen.getByText('5:20')).toBeInTheDocument();
    });

    it('shows TANJAKAN without GAP when the grade-adjusted pace is missing', () => {
        renderTiles({}, { ...baseSummary, max_grade_pct: 11 });
        expect(screen.getByText('TANJAKAN')).toBeInTheDocument();
        expect(screen.queryByText('GAP')).not.toBeInTheDocument();
    });

    it('hides the grade tiles on a flat run', () => {
        renderTiles({}, { ...baseSummary, max_grade_pct: 1, gap_pace: '5:20' });
        expect(screen.queryByText('TANJAKAN')).not.toBeInTheDocument();
        expect(screen.queryByText('GAP')).not.toBeInTheDocument();
    });

    it('skips the grade tiles when max_grade_pct is not a finite number (no "NaN%")', () => {
        renderTiles({}, { ...baseSummary, max_grade_pct: Number.NaN, gap_pace: '5:20' });
        expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
        expect(screen.queryByText('TANJAKAN')).not.toBeInTheDocument();
    });

    it('exposes the decoupling tile as a warning when |decoupling| > 8% on a cool run', () => {
        renderTiles({ weather_temp_c: 20 }, { decoupling_pct: 12.5 });
        expect(screen.getByText('+12.5%')).toHaveClass('text-ember');
        expect(screen.getByText('napas melar di paruh kedua')).toBeInTheDocument();
    });

    it('softens the decoupling tile with a heat explanation when the run was hot', () => {
        renderTiles({ weather_temp_c: 32 }, { decoupling_pct: 12.5 });
        expect(screen.getByText('+12.5%')).not.toHaveClass('text-ember');
        expect(screen.getByText('wajar, tadi panas 32°C')).toBeInTheDocument();
    });

    it('still flags a high decoupling on a run without weather data', () => {
        renderTiles({ weather_temp_c: null }, { decoupling_pct: 12.5 });
        expect(screen.getByText('+12.5%')).toHaveClass('text-ember');
    });

    it('does not apply the heat explanation to a large negative decoupling on a hot run', () => {
        // Negative decoupling means HR:pace improved in the second half — heat only
        // ever explains a positive drift, so a strongly negative value on a hot run
        // must still read as a plain warning, not "wajar, tadi panas".
        renderTiles({ weather_temp_c: 32 }, { decoupling_pct: -12.5 });
        expect(screen.getByText('-12.5%')).toHaveClass('text-ember');
        expect(screen.getByText('napas melar di paruh kedua')).toBeInTheDocument();
        expect(screen.queryByText(/wajar, tadi panas/)).not.toBeInTheDocument();
    });

    it('leaves a small decoupling untinted', () => {
        renderTiles();
        expect(screen.getByText('+4.5%')).toHaveClass('text-ink');
    });

    it('skips the decoupling tile when its value is not a finite number (no "NaN%")', () => {
        renderTiles({}, { decoupling_pct: Number.NaN });
        expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
        expect(screen.queryByText('DECOUPLING')).not.toBeInTheDocument();
    });

    it('spans the last tile across both columns when the tile count is odd', () => {
        // AVG HR + MAX HR + CADENCE, no grade/decoupling data — 3 tiles, an odd
        // count that would otherwise strand CADENCE alone in the 2-column grid.
        renderTiles({}, {});
        expect(screen.getByText('CADENCE').closest('div.rounded-xl')).toHaveClass('col-span-2');
        expect(screen.getByText('AVG HR').closest('div.rounded-xl')).not.toHaveClass('col-span-2');
    });

    it('does not span the last tile when the tile count is even', () => {
        // Default fixture yields 4 tiles (AVG HR, MAX HR, CADENCE, DECOUPLING).
        renderTiles();
        expect(screen.getByText('DECOUPLING').closest('div.rounded-xl')).not.toHaveClass('col-span-2');
    });

    it('renders the empty card when the run carries no technical numbers', () => {
        renderTiles(
            { average_heartrate: null, max_heartrate: null, average_cadence: null },
            {},
        );
        expect(screen.getByText(/Detail teknis-nya belum kebaca/)).toBeInTheDocument();
    });
});
