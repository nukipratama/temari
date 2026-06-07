import { render, screen, fireEvent, act } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MotionConfigProps } from 'framer-motion';
import AppShell from './AppShell';
import { setMockPage } from '@/test/setup';

// Spy on MotionConfig so we can assert the app tree is wrapped in it with
// reducedMotion="user" (it renders no DOM of its own, so we can't query it).
const motionConfigSpy = vi.fn();
vi.mock('framer-motion', async (importOriginal) => {
    const actual = await importOriginal<typeof import('framer-motion')>();
    return {
        ...actual,
        MotionConfig: (props: MotionConfigProps) => {
            motionConfigSpy(props.reducedMotion);
            return actual.MotionConfig(props);
        },
    };
});

const andiUser = { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null };

describe('AppShell', () => {
    afterEach(() => {
        delete document.body.dataset.timeOfDay;
        motionConfigSpy.mockClear();
    });

    it('wraps the app tree in MotionConfig reducedMotion="user"', () => {
        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false });
        render(<AppShell><p>x</p></AppShell>);
        expect(motionConfigSpy).toHaveBeenCalledWith('user');
    });

    it('wraps the no-nav branch in MotionConfig reducedMotion="user" too', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(<AppShell withNav={false}><p>x</p></AppShell>);
        expect(motionConfigSpy).toHaveBeenCalledWith('user');
    });

    it('sets a data-time-of-day attribute on body via useDawnShift', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>x</p>
            </AppShell>,
        );
        expect(document.body.dataset.timeOfDay).toMatch(/^(dawn|morning|day|dusk|night)$/);
    });

    it('renders the 4 primary tabs + children by default', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.getByText('child content')).toBeInTheDocument();
        ['Hari Ini', 'Koleksi', 'Riwayat', 'Aku'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    it('omits nav chrome when withNav is false', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(
            <AppShell withNav={false}>
                <p>only child</p>
            </AppShell>,
        );
        expect(screen.queryByText('Hari Ini')).not.toBeInTheDocument();
        expect(screen.getByText('only child')).toBeInTheDocument();
    });

    it('shows AksesoriUnlockModal and dismisses it when a major unlock is flashed', async () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {
                unlock: { unlock_key: 'accessory.ikat_kepala_epik', name: 'Ikat Kepala Luar Biasa', icon: 'mdi:star', is_major: true },
            },
            demoLoginEnabled: false,
        });
        render(<AppShell><p>x</p></AppShell>);
        expect(screen.getByText(/Ikat Kepala Luar Biasa/)).toBeInTheDocument();
        // Clicking "Nanti aja" triggers onClose (covers () => setMajorUnlock(null))
        await act(async () => { fireEvent.click(screen.getByText('Nanti aja')); });
    });

    it('fires PR modal when CardReveal is dismissed on a PR run', async () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
            pendingReveal: {
                card_id: 7, activity_id: 99, rarity: 'common', special_move: 'Langkah Ringan',
                badges: null, detail_name: 'Pagi', distance_m: 5000, moving_time_sec: 1800,
                trimp_edwards: 50, is_pr: true, pr_category_label: '5K', pr_time_display: '22:15',
            },
        });
        globalThis.fetch = Object.assign(
            () => Promise.resolve(new Response('{"seen":true}', { status: 200 })),
            { preload: () => {} },
        ) as typeof fetch;
        render(<AppShell><p>x</p></AppShell>);
        // The foil-wrapped card always shows a ghost "Tutup" button; dismissing
        // a PR reveal fires onPrMoment → PRMomentModal.
        await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Tutup' })); });
        // PRMomentModal fires — PR time is visible
        expect(screen.getByText('22:15')).toBeInTheDocument();
        // Clicking close triggers the onClose callback (covers () => setPrModal(null))
        await act(async () => { fireEvent.click(screen.getByLabelText('Tutup')); });
    });
});
