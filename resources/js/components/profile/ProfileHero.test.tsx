import { render, screen } from '@testing-library/react';
import { Footprints, Route } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import ProfileHero from './ProfileHero';

const STATS = [
    { icon: Route, label: 'Total km', value: '284.6' },
    { icon: Footprints, label: 'Total runs', value: '42' },
];

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

function renderHero(
    overrides: Partial<Parameters<typeof ProfileHero>[0]> = {},
) {
    return render(
        <ProfileHero
            mood="easy"
            firstRunAt="2026-06-12"
            timeInZone={null}
            stats={STATS}
            {...overrides}
        />,
    );
}

describe('ProfileHero', () => {
    it('draws Temari as the panel watermark, posed to the daily mood', () => {
        const { container } = renderHero({ mood: 'gassed' });
        const mascot = container.querySelector('svg[data-mascot]');

        expect(mascot?.getAttribute('data-mascot')).toBe('gassed');
        expect(mascot?.getAttribute('width')).toBe('200');
    });

    it('bleeds the watermark off the top-right corner at every width', () => {
        const { container } = renderHero();
        const classes =
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('class')
                ?.split(/\s+/) ?? [];

        expect(classes).toEqual(
            expect.arrayContaining(['-top-20', '-right-14']),
        );
        expect(classes.filter((c) => c.startsWith('min-['))).toEqual([]);
    });

    it('renders the eyebrow with the merged est. date and every stat tile', () => {
        renderHero();

        expect(
            screen.getByText('what temari says about you · est. 12 jun 2026'),
        ).toBeInTheDocument();
        expect(screen.getByText('284.6')).toBeInTheDocument();
        expect(screen.getByText('Total runs')).toBeInTheDocument();
    });

    it('omits the est. suffix when the athlete has no first run yet', () => {
        renderHero({ firstRunAt: null });

        expect(
            screen.getByText('what temari says about you'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/est\./)).not.toBeInTheDocument();
    });

    it('renders the zone bar only when zone time exists', () => {
        renderHero();
        expect(screen.queryByText(/Time in zone/)).not.toBeInTheDocument();

        renderHero({ timeInZone: { Z2: 100 } });
        expect(
            screen.getByText(/Time in zone · last 12 weeks/),
        ).toBeInTheDocument();
    });

    it("holds the zone bar's space with a skeleton while it is still deferred", () => {
        const { container } = renderHero({ timeInZone: undefined });

        expect(screen.queryByText(/Time in zone/)).not.toBeInTheDocument();
        expect(container.querySelector('.skeleton')).not.toBeNull();
    });

    it('thinks in the corner while the voice is being written', () => {
        const { container } = renderHero({
            mood: 'easy',
            voice: {
                id: 3,
                status: 'queued',
                content: null,
                type: 'profile_voice',
                subject_type: 'profile_voice_user',
                subject_id: 1,
                discriminator: '2026-W24',
            },
        });

        expect(
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('data-mascot'),
        ).toBe('thinking');
    });

    it('renders the narration quote when a done analysis is passed', () => {
        renderHero({
            voice: {
                id: 3,
                status: 'done',
                content: 'You keep showing up on the hard days.',
                type: 'profile_voice',
                subject_type: 'profile_voice_user',
                subject_id: 1,
                discriminator: '2026-W24',
            },
        });

        expect(
            screen.getByText(/You keep showing up on the hard days/),
        ).toBeInTheDocument();
    });

    it('splits a multi-sentence narration into a serif lead and sans prose', () => {
        const { container } = renderHero({
            voice: {
                id: 3,
                status: 'done',
                content:
                    'You keep showing up on the hard days. That adds up more than any single fast one.',
                type: 'profile_voice',
                subject_type: 'profile_voice_user',
                subject_id: 1,
                discriminator: '2026-W24',
            },
        });

        expect(container.querySelector('.font-serif.italic')?.textContent).toBe(
            'You keep showing up on the hard days.',
        );
        expect(container.querySelector('.narration')?.textContent).toBe(
            'That adds up more than any single fast one.',
        );
    });

    it('renders a caller-supplied action', () => {
        renderHero({ action: <button type="button">Reconnect</button> });

        expect(
            screen.getByRole('button', { name: 'Reconnect' }),
        ).toBeInTheDocument();
    });

    it('shows an empty message instead of the stat rail when there are no stats', () => {
        renderHero({ stats: [] });

        expect(
            screen.getByText(/no runs yet\. sync your first one/),
        ).toBeInTheDocument();
        expect(screen.queryByText('Total runs')).not.toBeInTheDocument();
    });
});
