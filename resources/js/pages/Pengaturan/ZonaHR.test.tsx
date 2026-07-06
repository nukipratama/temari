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

    it('updates the derived-zone preview when the max HR changes', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const previewZ1 = screen.getByTestId('preview-Z1');
        expect(previewZ1.textContent).toContain('116');

        fireEvent.change(screen.getByLabelText('Max HR'), { target: { value: '200' } });

        const expected = deriveZones(200, 55);
        expect(screen.getByTestId('preview-Z1').textContent).toContain(String(expected.Z1.lo));
        expect(screen.getByTestId('preview-Z5').textContent).toContain(String(expected.Z5.lo));
    });

    it('lets the user override a manual boundary', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);

        const z2Lo = screen.getByTestId('zone-Z2-lo') as HTMLInputElement;
        expect(z2Lo.value).toBe('138');

        fireEvent.change(z2Lo, { target: { value: '142' } });

        expect((screen.getByTestId('zone-Z2-lo') as HTMLInputElement).value).toBe('142');
    });

    it('shows the custom-profile copy when one exists', () => {
        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile />);
        expect(screen.getByText(/udah punya zona custom/i)).toBeInTheDocument();
    });

    it('surfaces a server field error under the input', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            errors: { max_hr: 'Max HR harus di antara 120 dan 220 bpm.' },
            demoLoginEnabled: false,
        });

        render(<ZonaHR profile={DEFAULT_PROFILE} hasCustomProfile={false} />);
        const maxHrField = screen.getByLabelText('Max HR').closest('label');
        expect(within(maxHrField as HTMLElement).getByText(/di antara 120 dan 220/i)).toBeInTheDocument();
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

        fireEvent.click(screen.getByRole('button', { name: 'Simpan zona' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/pengaturan/zona',
            {
                max_hr: 180,
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
