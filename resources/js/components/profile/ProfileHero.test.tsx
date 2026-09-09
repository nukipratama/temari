import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import ProfileHero from './ProfileHero';

const STATS = [
    { icon: 'mdi:map-marker-distance', label: 'Total km', value: '284.6' },
    { icon: 'mdi:run', label: 'Total runs', value: '42' },
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
            firstRunAt="2026-06-12"
            memberSince="2026-06-12"
            timeInZone={null}
            stats={STATS}
            {...overrides}
        />,
    );
}

describe('ProfileHero', () => {
    it('renders the eyebrow, the est. date and every stat tile', () => {
        renderHero();

        expect(
            screen.getByText('★ What temari says about you'),
        ).toBeInTheDocument();
        expect(screen.getByText('Est. 12 jun 2026')).toBeInTheDocument();
        expect(screen.getByText('284.6')).toBeInTheDocument();
        expect(screen.getByText('Total runs')).toBeInTheDocument();
    });

    it('omits the est. line when the athlete has no first run yet', () => {
        renderHero({ firstRunAt: null });

        expect(screen.queryByText(/^Est\./)).not.toBeInTheDocument();
    });

    it('renders the join-date block, which CSS reveals only at 900px', () => {
        renderHero();

        expect(screen.getByText('With temari since')).toBeInTheDocument();
    });

    it('omits the join-date block when member_since is missing', () => {
        renderHero({ memberSince: null });

        expect(screen.queryByText('With temari since')).not.toBeInTheDocument();
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

    it('renders a caller-supplied action', () => {
        renderHero({ action: <button type="button">Reconnect</button> });

        expect(
            screen.getByRole('button', { name: 'Reconnect' }),
        ).toBeInTheDocument();
    });

    it('draws the flag above the voice block, not under it', () => {
        renderHero({
            voice: {
                id: 9,
                status: 'done',
                content: 'You show up.',
                type: 'profile_voice',
                subject_type: 'profile_voice_user',
                subject_id: 1,
                discriminator: null,
            },
        });

        const flag = screen.getByRole('button', { name: 'flag this read' });
        const voice = screen.getByText('You show up.');

        expect(flag.parentElement).toHaveClass('justify-end');
        expect(
            flag.compareDocumentPosition(voice) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });
});
