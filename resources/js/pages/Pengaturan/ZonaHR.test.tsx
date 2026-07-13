import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ZonaHR, { deriveZones } from './ZonaHR';
import { makeUser, setMockPage } from '@/test/setup';

const DEFAULT_PROFILE = {
    max_hr: 180,
    resting_hr: 55,
    hr_zones: {
        Z1: { lo: 116, hi: 138 },
        Z2: { lo: 138, hi: 154 },
        Z3: { lo: 154, hi: 168 },
        Z4: { lo: 168, hi: 176 },
        Z5: { lo: 176, hi: 999 },
    },
    optimal_cadence_spm: 170,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        errors: {},
        demoLoginEnabled: false,
    });
});

describe('deriveZones', () => {
    it('reproduces the config defaults at max 180 resting 55', () => {
        expect(deriveZones(180, 55)).toEqual(DEFAULT_PROFILE.hr_zones);
    });
});

describe('ZonaHR', () => {
    it('renders the forward-only note', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);
        expect(screen.getByText(/dipakai ke semua lari berikutnya/i)).toBeInTheDocument();
    });

    it('loads the zone table from saved data, not an auto-calc', () => {
        const stored = {
            ...DEFAULT_PROFILE,
            hr_zones: { ...DEFAULT_PROFILE.hr_zones, Z2: { lo: 141, hi: 157 } },
        };
        render(<ZonaHR profile={stored} hasCustomProfile source="manual" />);

        // The input shows the saved value (141), not the Karvonen-derived 138.
        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe('141');
        expect(deriveZones(180, 55).Z2.lo).toBe(138);
    });

    it('keeps Simpan zona disabled until something changes', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        expect(screen.getByRole('button', { name: 'Simpan zona' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Max HR'), { target: { value: '185' } });

        expect(screen.getByRole('button', { name: 'Simpan zona' })).toBeEnabled();
    });

    it('lets the user override a manual boundary', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const z2Lo = screen.getByTestId('zone-Z2-lo') as HTMLInputElement;
        expect(z2Lo.value).toBe('138');

        fireEvent.change(z2Lo, { target: { value: '142' } });

        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe('142');
    });

    it('shows the default-zone status when no profile is stored', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} source="default" stravaSyncedLabel={null} />);
        expect(screen.getByText('Zona standar')).toBeInTheDocument();
        expect(screen.getByText(/masih pakai zona standar/i)).toBeInTheDocument();
    });

    it('shows the manual status when the user set zones themselves', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile source="manual" stravaSyncedLabel={null} />);
        expect(screen.getByText('Diatur manual')).toBeInTheDocument();
        expect(screen.getByText(/atur zona sendiri/i)).toBeInTheDocument();
    });

    it('shows the strava source with its last-synced label', () => {
        render(
            <ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile source="strava" stravaSyncedLabel="10 Jul 2026, 10:18" />,
        );
        expect(screen.getByText('Disinkron dari Strava')).toBeInTheDocument();
        expect(screen.getByText(/terakhir sinkron 10 Jul 2026, 10:18/i)).toBeInTheDocument();
    });

    it('shows neither escape action on the default source', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} source="default" />);
        expect(screen.queryByRole('button', { name: /Balik ke zona standar/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Sinkron ulang dari Strava/i })).not.toBeInTheDocument();
    });

    it('resets to default when the reset button is clicked', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile source="manual" />);

        fireEvent.click(screen.getByRole('button', { name: /Balik ke zona standar/i }));

        expect(router.delete).toHaveBeenCalledWith(
            '/pengaturan/zona',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('offers a Strava re-sync only on a manual source with the scope', () => {
        const { rerender } = render(
            <ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile source="manual" canSyncFromStrava />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Sinkron ulang dari Strava/i }));
        expect(router.post).toHaveBeenCalledWith(
            '/pengaturan/zona/sinkron-strava',
            {},
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );

        // No scope → no re-sync affordance (reset still shows).
        rerender(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile source="manual" canSyncFromStrava={false} />);
        expect(screen.queryByRole('button', { name: /Sinkron ulang dari Strava/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Balik ke zona standar/i })).toBeInTheDocument();
    });

    it('surfaces a server field error under the input', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            errors: { max_hr: 'Max HR harus di antara 120 dan 220 bpm.' },
            demoLoginEnabled: false,
        });

        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);
        const maxHrInput = screen.getByLabelText('Max HR');
        const maxHrField = maxHrInput.closest('label');
        const errorEl = within(maxHrField as HTMLElement).getByText(/di antara 120 dan 220/i);
        expect(errorEl).toBeInTheDocument();
        // The input is programmatically associated with its error for screen readers.
        expect(maxHrInput).toHaveAttribute('aria-invalid', 'true');
        expect(maxHrInput.getAttribute('aria-describedby')).toBe(errorEl.getAttribute('id'));
    });

    it('lets the user override a manual hi boundary', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const z2Hi = screen.getByTestId('zone-Z2-hi') as HTMLInputElement;
        expect(z2Hi.value).toBe('154');

        fireEvent.change(z2Hi, { target: { value: '150' } });

        expect((screen.getByTestId('zone-Z2-hi') as HTMLInputElement).value).toBe('150');
    });

    it('recomputes manual zones from Max & Resting on demand', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        fireEvent.change(screen.getByTestId('zone-Z2-lo'), { target: { value: '999' } });
        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe('999');

        fireEvent.click(screen.getByRole('button', { name: 'Hitung otomatis dari Max & Resting' }));

        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe(
            String(deriveZones(180, 55).Z2.lo),
        );
    });

    it('submits the current zones and toggles processing around the request', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        // Save is disabled until dirty; bump Max HR so the payload is sendable.
        fireEvent.change(screen.getByLabelText('Max HR'), { target: { value: '185' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan zona' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/pengaturan/zona',
            {
                max_hr: 185,
                resting_hr: 55,
                zones: [
                    { lo: 116, hi: 138 },
                    { lo: 138, hi: 154 },
                    { lo: 154, hi: 168 },
                    { lo: 168, hi: 176 },
                    { lo: 176, hi: 999 },
                ],
            },
            expect.objectContaining({
                preserveScroll: true,
                onStart: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );

        const options = vi.mocked(router.patch).mock.calls.at(-1)?.[2] as {
            onStart: () => void;
            onFinish: () => void;
        };
        // onStart/onFinish call setProcessing directly; router.patch is mocked
        // so nothing else invokes them, and calling them bare (outside act())
        // is what React warns about.
        act(() => {
            options.onStart();
            options.onFinish();
        });
    });
});
