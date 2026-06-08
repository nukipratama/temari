import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import LastLariCard from './LastLariCard';
import type { ActivityDetail } from '@/types/inertia';

const richRun: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Pagi negatif-split',
    start_date_local: '2026-05-20T07:00',
    distance: 5280,
    moving_time: 2400,
    average_heartrate: 145,
    trimp_edwards: 87,
    location_name: 'Gelora Bung Karno, Jakarta Pusat',
    weather_temp_c: 28,
    weather_humidity_pct: 70,
    weather_rain_detected: false,
};

const bareRun: ActivityDetail = {
    id: 2,
    activity_id: 100,
    name: null,
    start_date_local: '2026-05-21T07:00',
    distance: 0,
    moving_time: 0,
    average_heartrate: null,
    trimp_edwards: null,
    location_name: null,
    weather_temp_c: null,
    weather_humidity_pct: null,
    weather_rain_detected: null,
};

describe('LastLariCard', () => {
    it('renders name, location, pace, and an optional note', () => {
        render(
            <LastLariCard run={richRun} pose="proud" note={{ oneline: 'Sesi yang mantap.', mood: 'nyala' }} />,
        );
        expect(screen.getByText('Pagi negatif-split')).toBeInTheDocument();
        expect(screen.getByText(/Gelora Bung Karno/)).toBeInTheDocument();
        expect(screen.getByText('Sesi yang mantap.')).toBeInTheDocument();
        // pace renders as a value (not the "—" fallback).
        expect(screen.getAllByText(/\/km$/).length).toBeGreaterThan(0);
        expect(screen.getByText('Lihat detail lari →')).toBeInTheDocument();
    });

    it('uses the "Lari" name fallback and em-dash placeholders for a bare run', () => {
        render(<LastLariCard run={bareRun} pose="observational" note={null} />);
        expect(screen.getByText('Lari')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(2);
        expect(screen.queryByText(/Gelora/)).not.toBeInTheDocument();
    });

    it('links to the activity detail page', () => {
        render(<LastLariCard run={richRun} pose="proud" note={null} />);
        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', '/aktivitas/99');
    });
});
