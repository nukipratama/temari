import { render, screen, fireEvent, act } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MotionConfigProps } from 'framer-motion';
import AppShell from './AppShell';
import { setMockPage } from '@/test/setup';
import type { PendingReveal } from '@/types/inertia';

const pendingCard: PendingReveal = {
    card_id: 1,
    activity_id: 1,
    rarity: 'common',
    special_move: 'Pagi Santai',
    mood: 'adem',
    badges: null,
    detail_name: 'Easy run',
    distance_m: 5000,
    moving_time_sec: 1800,
    trimp_edwards: 42,
    public_share_url: '/aktivitas/1',
    edition: { index: 1, total: 1 },
};

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
        // <main> keeps bottom clearance for the fixed mobile bottom nav (cleared on lg).
        const main = document.getElementById('main-content');
        expect(main?.className).toContain('pb-28');
        expect(main?.className).toContain('lg:pb-0');
    });

    // The shell owns the cross-page banners; pages no longer render them, so
    // this is the only place their mounting is asserted.
    it('mounts the Strava zone reconnect banner as shell chrome', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {},
            demoLoginEnabled: false,
            stravaZoneScopeMissing: true,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.getByText(/Sambungin ulang Strava/)).toBeInTheDocument();
    });

    // The content region used to be keyed on the Inertia component name, which
    // tore down and rebuilt the whole subtree on every visit and replayed an
    // enter animation starting at opacity 0 — so a navigation read as
    // "old page -> blank -> fade in". Both are gone; this pins that.
    it('does not remount the content region when the page component changes', () => {
        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false }, '/', 'HariIni');
        const { rerender } = render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );
        const before = document.getElementById('main-content');

        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false }, '/kartu', 'Koleksi/Kartu');
        rerender(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        expect(document.getElementById('main-content')).toBe(before);
    });

    it('carries no enter animation that would blank the content first', () => {
        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false });
        render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        expect(document.getElementById('main-content')?.className).not.toContain('page-enter');
    });

    it('keeps the content region mounted across a partial reload of the same page', () => {
        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false }, '/aktivitas', 'Riwayat/Jejak');
        const { rerender } = render(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );
        const before = document.getElementById('main-content');

        // Same component, new query string — a filter/`only:` refresh.
        setMockPage({ auth: { user: andiUser }, flash: {}, demoLoginEnabled: false }, '/aktivitas?range=8w', 'Riwayat/Jejak');
        rerender(
            <AppShell>
                <p>body</p>
            </AppShell>,
        );

        expect(document.getElementById('main-content')).toBe(before);
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
                unlock: { unlock_key: 'accessory.ikat_kepala_epik', name: 'Ikat Kepala Istimewa', icon: 'mdi:star', is_major: true },
            },
            demoLoginEnabled: false,
        });
        render(<AppShell><p>x</p></AppShell>);
        expect(screen.getByText(/Ikat Kepala Istimewa/)).toBeInTheDocument();
        // Clicking "Nanti aja" triggers onClose (covers () => setMajorUnlock(null))
        await act(async () => { fireEvent.click(screen.getByText('Nanti aja')); });
    });

    it('defers the aksesori-unlock modal while a CardReveal pack is pending, so they never stack', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {
                unlock: { unlock_key: 'accessory.ikat_kepala_epik', name: 'Ikat Kepala Istimewa', icon: 'mdi:star', is_major: true },
            },
            pendingReveal: pendingCard,
            demoLoginEnabled: false,
        });
        render(<AppShell><p>x</p></AppShell>);
        // CardReveal (the pack) takes priority: it's shown...
        expect(screen.getByText('Sync masuk')).toBeInTheDocument();
        // ...and the aksesori modal is held back, even though a major unlock fired.
        expect(screen.queryByText(/Ikat Kepala Istimewa/)).not.toBeInTheDocument();
    });

    it('hides the UnlockToast while a CardReveal pack is pending', () => {
        setMockPage({
            auth: { user: andiUser },
            flash: {
                unlock: { unlock_key: 'accessory.medal_emas', name: 'Medali Emas', icon: 'mdi:medal', is_major: false },
            },
            pendingReveal: pendingCard,
            demoLoginEnabled: false,
        });
        render(<AppShell><p>x</p></AppShell>);
        expect(screen.getByText('Sync masuk')).toBeInTheDocument();
        expect(screen.queryByText('Unlock baru')).not.toBeInTheDocument();
    });
});
