import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KondisiCard from './KondisiCard';
import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-05-11',
    runs: 4,
    distance_km: 35.5,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    avg_decoupling: 3.2,
};

describe('KondisiCard', () => {
    it('renders all four metric rows with formatted values', () => {
        render(<KondisiCard load={load} snapshot={snapshot} />);
        ['Fondasi', 'Kelelahan', 'Beban', 'Variasi'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
        expect(screen.getByText('42.0')).toBeInTheDocument(); // ctl toFixed(1)
        expect(screen.getByText('44.5')).toBeInTheDocument(); // atl toFixed(1)
        expect(screen.getByText('384')).toBeInTheDocument(); // strain rounded
        expect(screen.getByText('1.20')).toBeInTheDocument(); // monotony toFixed(2)
    });

    it('shows the "7 hari" subtitle and a technical-detail link', () => {
        render(<KondisiCard load={load} snapshot={snapshot} />);
        expect(screen.getByText(/7 hari/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Detail teknis/ })).toHaveAttribute('href', '/aktivitas');
    });

    it('falls back to em-dash values and "belum cukup data" when load and snapshot are null', () => {
        render(<KondisiCard load={null} snapshot={null} />);
        expect(screen.getByText(/belum cukup data/)).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBe(4);
    });
});
