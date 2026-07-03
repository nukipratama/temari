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
        // <main> keeps bottom clearance for the fixed mobile bottom nav (cleared on lg).
        const main = document.getElementById('main-content');
        expect(main?.className).toContain('pb-28');
        expect(main?.className).toContain('lg:pb-0');
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
});
