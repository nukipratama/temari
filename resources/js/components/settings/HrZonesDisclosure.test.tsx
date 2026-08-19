import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import HrZonesDisclosure, {
    deriveZones,
    type HrZonesPayload,
} from './HrZonesDisclosure';

const DEFAULT_PROFILE = {
    max_hr: 180,
    resting_hr: 55,
    hr_zones: deriveZones(180, 55),
    optimal_cadence_spm: 170,
};

const DEFAULT_PAYLOAD: HrZonesPayload = {
    profile: DEFAULT_PROFILE,
    source: 'default',
    stravaSyncedLabel: null,
    canSyncFromStrava: false,
};

function open() {
    fireEvent.click(screen.getByRole('button', { name: /HR zones/ }));
}

describe('deriveZones', () => {
    it('derives ascending, gapless zones with an open-ended Z5', () => {
        const zones = deriveZones(190, 50);
        expect(zones.Z1.lo).toBe(118);
        expect(zones.Z1.hi).toBe(zones.Z2.lo);
        expect(zones.Z5.hi).toBe(999);
    });
});

describe('HrZonesDisclosure', () => {
    it('stays collapsed until the trigger is clicked, naming the current source', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        expect(screen.getByText('HR zones')).toBeInTheDocument();
        expect(
            screen.getByText(/you're on the defaults for now/i),
        ).toBeInTheDocument();
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

    it('recomputes zones from Max/Resting HR only when Auto-calculate is pressed', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByLabelText('Max HR'), {
            target: { value: '200' },
        });
        // Editing the field alone must not touch the rendered zones.
        expect(screen.getByTestId('zone-Z1-lo')).toHaveValue(
            DEFAULT_PROFILE.hr_zones.Z1.lo,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: /Auto-calculate from Max & Resting/,
            }),
        );

        const expected = deriveZones(200, DEFAULT_PROFILE.resting_hr);
        expect(screen.getByTestId('zone-Z1-lo')).toHaveValue(expected.Z1.lo);
    });

    it('keeps each zone boundary individually editable', () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByTestId('zone-Z2-lo'), {
            target: { value: '145' },
        });
        expect(screen.getByTestId('zone-Z2-lo')).toHaveValue(145);
    });

    it("renders Z5's upper bound as an unbounded, non-editable symbol", () => {
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        expect(screen.getByTestId('zone-Z5-hi')).toHaveTextContent('∞');
        expect(
            screen.queryByLabelText('Z5 upper bound'),
        ).not.toBeInTheDocument();
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

    it('submits max_hr, resting_hr and the five zones on save', () => {
        vi.mocked(router.patch).mockReset();
        render(<HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />);
        open();

        fireEvent.change(screen.getByLabelText('Resting HR'), {
            target: { value: '50' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Save zones/ }));

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/zones',
            expect.objectContaining({
                max_hr: DEFAULT_PROFILE.max_hr,
                resting_hr: 50,
                zones: expect.arrayContaining([
                    expect.objectContaining(DEFAULT_PROFILE.hr_zones.Z1),
                ]),
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows Reset action only once the source is no longer default', () => {
        const { rerender } = render(
            <HrZonesDisclosure hrZones={DEFAULT_PAYLOAD} />,
        );
        open();
        expect(
            screen.queryByRole('button', { name: /Reset to default zones/ }),
        ).not.toBeInTheDocument();

        rerender(
            <HrZonesDisclosure
                hrZones={{ ...DEFAULT_PAYLOAD, source: 'manual' }}
            />,
        );
        expect(
            screen.getByRole('button', { name: /Reset to default zones/ }),
        ).toBeInTheDocument();
    });

    it('only shows Resync from Strava when canSyncFromStrava is true and the source is manual', () => {
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
            screen.getByRole('button', { name: /Resync from Strava/ }),
        ).toBeInTheDocument();
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
            screen.getByRole('button', { name: /Reset to default zones/ }),
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
