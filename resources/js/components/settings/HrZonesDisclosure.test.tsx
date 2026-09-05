import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

import HrZonesDisclosure, {
    deriveBounds,
    toZonePairs,
    type HrZonesPayload,
} from './HrZonesDisclosure';

const DEFAULT_BOUNDS = deriveBounds(180, 55);

const DEFAULT_PROFILE = {
    max_hr: 180,
    resting_hr: 55,
    hr_zones: {
        Z1: { lo: DEFAULT_BOUNDS.Z1, hi: DEFAULT_BOUNDS.Z2 },
        Z2: { lo: DEFAULT_BOUNDS.Z2, hi: DEFAULT_BOUNDS.Z3 },
        Z3: { lo: DEFAULT_BOUNDS.Z3, hi: DEFAULT_BOUNDS.Z4 },
        Z4: { lo: DEFAULT_BOUNDS.Z4, hi: DEFAULT_BOUNDS.Z5 },
        Z5: { lo: DEFAULT_BOUNDS.Z5, hi: 999 },
    },
    optimal_cadence_spm: 170,
};

const DEFAULT_PAYLOAD: HrZonesPayload = {
    profile: DEFAULT_PROFILE,
    source: 'default',
    stravaSyncedLabel: null,
    canSyncFromStrava: false,
};

function open() {
    fireEvent.click(screen.getByRole('button', { name: /heart-rate zones/ }));
}

describe('deriveBounds', () => {
    it('derives ascending lower bounds from the heart-rate reserve', () => {
        const bounds = deriveBounds(190, 50);
        expect(bounds.Z1).toBe(118);
        expect(bounds.Z2).toBeGreaterThan(bounds.Z1);
        expect(bounds.Z5).toBeGreaterThan(bounds.Z4);
    });
});

describe('toZonePairs', () => {
    // The server rejects any submission where a zone's hi is not the next
    // zone's lo, so the pairs are reconstituted rather than entered.
    it('widens five lower bounds into gapless pairs with an open-ended Z5', () => {
        expect(toZonePairs(deriveBounds(190, 50))).toEqual([
            { lo: 118, hi: 143 },
            { lo: 143, hi: 161 },
            { lo: 161, hi: 177 },
            { lo: 177, hi: 186 },
            { lo: 186, hi: 999 },
        ]);
    });
});

describe('HrZonesDisclosure', () => {
    beforeEach(() => {
        setMockPage({ errors: {} });
    });

    it('stays collapsed until the trigger is clicked, naming the current source', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        expect(screen.getByText('heart-rate zones')).toBeInTheDocument();
        expect(screen.getByText('using default estimates')).toBeInTheDocument();
        expect(screen.queryByLabelText('Max HR')).not.toBeInTheDocument();

        open();
        expect(screen.getByLabelText('Max HR')).toBeInTheDocument();
    });

    it('names Strava as the source with its last-synced label when collapsed', () => {
        render(
            <HrZonesDisclosure
                hrZones={{
                    ...DEFAULT_PAYLOAD,
                    source: 'strava',
                    stravaSyncedLabel: '3 days ago',
                }}
            />,
        );
        expect(
            screen.getByText(/Synced from Strava · last synced 3 days ago/),
        ).toBeInTheDocument();
    });

    // The prototype draws one bound per zone, and the extra `hi` fields the
    // page used to render could only ever express submissions the server
    // rejects.
    it('draws one bound per zone, never a second upper-bound field', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        for (const key of ['Z1', 'Z2', 'Z3', 'Z4', 'Z5']) {
            expect(screen.getByTestId(`zone-${key}-lo`)).toBeInTheDocument();
            expect(
                screen.queryByTestId(`zone-${key}-hi`),
            ).not.toBeInTheDocument();
        }
    });

    // Reflow #10: the prototype's own wide step on this two-field grid.
    it('takes the wide four-column step on the max/resting grid', () => {
        const { container } = render(
            <HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />,
        );
        open();

        expect(
            container.querySelector('.min-\\[900px\\]\\:grid-cols-4'),
        ).not.toBeNull();
    });

    it('recomputes zones from Max/Resting HR only when Auto-calculate is pressed', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByLabelText('Max HR'), {
            target: { value: '200' },
        });
        // Editing the field alone must not touch the rendered zones.
        expect(screen.getByTestId('zone-Z1-lo')).toHaveValue(DEFAULT_BOUNDS.Z1);

        fireEvent.click(screen.getByRole('button', { name: 'Auto-calculate' }));

        expect(screen.getByTestId('zone-Z1-lo')).toHaveValue(
            deriveBounds(200, DEFAULT_PROFILE.resting_hr).Z1,
        );
    });

    it('keeps each zone bound individually editable', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByTestId('zone-Z2-lo'), {
            target: { value: '145' },
        });
        expect(screen.getByTestId('zone-Z2-lo')).toHaveValue(145);
    });

    it('disables Save until something actually changed', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        expect(
            screen.getByRole('button', { name: /Save zones/ }),
        ).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Max HR'), {
            target: { value: '200' },
        });
        expect(
            screen.getByRole('button', { name: /Save zones/ }),
        ).toBeEnabled();
    });

    it('submits max_hr, resting_hr and the five reconstituted pairs on save', () => {
        vi.mocked(router.patch).mockReset();
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByLabelText('Resting HR'), {
            target: { value: '50' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Save zones/ }));

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/zones',
            {
                max_hr: DEFAULT_PROFILE.max_hr,
                resting_hr: 50,
                zones: toZonePairs(DEFAULT_BOUNDS),
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    // A server complaint about `zones.N.hi` is now a complaint about the next
    // zone's lower bound, since that is the only field the user can reach.
    it('marks the next zone invalid when the server rejects a derived upper bound', () => {
        setMockPage({
            errors: { 'zones.1.hi': 'Zone upper bound must be greater.' },
        });
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        expect(screen.getByTestId('zone-Z3-lo')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            /has to start above the one before it/,
        );
    });

    it('shows Reset action only once the source is no longer default', () => {
        const { rerender } = render(
            <HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />,
        );
        open();
        expect(
            screen.queryByRole('button', { name: /Reset to default/ }),
        ).not.toBeInTheDocument();

        rerender(
            <HrZonesDisclosure
                hrZones={{ ...DEFAULT_PAYLOAD, source: 'manual' }}
            />,
        );
        expect(
            screen.getByRole('button', { name: /Reset to default/ }),
        ).toBeInTheDocument();
    });

    it('flashes Saved once the patch succeeds', () => {
        vi.mocked(router.patch).mockReset();
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByLabelText('Max HR'), {
            target: { value: '200' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Save zones/ }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        const [, , options] = vi.mocked(router.patch).mock.calls[0] as [
            string,
            unknown,
            {
                onStart?: () => void;
                onSuccess?: () => void;
                onFinish?: () => void;
            },
        ];
        act(() => {
            options.onStart?.();
            options.onSuccess?.();
            options.onFinish?.();
        });

        expect(screen.getByRole('status')).toHaveTextContent('Saved');
    });

    it('only shows resync from Strava when canSyncFromStrava is true and the source is manual', () => {
        render(
            <HrZonesDisclosure
                hrZones={{
                    ...DEFAULT_PAYLOAD,
                    source: 'manual',
                    canSyncFromStrava: true,
                }}
            />,
        );
        open();
        expect(
            screen.getByRole('button', { name: /resync from Strava/ }),
        ).toBeInTheDocument();
    });

    it('resyncs from Strava and reloads just the hrZones prop', () => {
        vi.mocked(router.post).mockReset();
        vi.mocked(router.reload).mockReset();

        render(
            <HrZonesDisclosure
                hrZones={{
                    ...DEFAULT_PAYLOAD,
                    source: 'manual',
                    canSyncFromStrava: true,
                }}
            />,
        );
        open();
        fireEvent.click(
            screen.getByRole('button', { name: /resync from Strava/ }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/settings/zones/resync-strava',
            {},
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );

        const [, , options] = vi.mocked(router.post).mock.calls[0] as [
            string,
            unknown,
            { onSuccess?: () => void },
        ];
        act(() => options.onSuccess?.());

        expect(router.reload).toHaveBeenCalledWith({ only: ['hrZones'] });
    });

    it('deletes and reloads just the hrZones prop when Reset to default is clicked', () => {
        vi.mocked(router.delete).mockReset();
        vi.mocked(router.reload).mockReset();

        render(
            <HrZonesDisclosure
                hrZones={{ ...DEFAULT_PAYLOAD, source: 'manual' }}
            />,
        );
        open();
        fireEvent.click(
            screen.getByRole('button', { name: /Reset to default/ }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/zones',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );

        // Simulate the server round-trip completing.
        const [, options] = vi.mocked(router.delete).mock.calls[0] as [
            string,
            { onSuccess?: () => void },
        ];
        options.onSuccess?.();

        expect(router.reload).toHaveBeenCalledWith({ only: ['hrZones'] });
    });
});
