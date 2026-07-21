import { act, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MobileTopBar from './MobileTopBar';
import { makeUser, setMockPage } from '@/test/setup';

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Beranda')).toHaveAttribute('href', '/');
    });

    // Roots show identity, pushed screens show a way out — the native split.
    // Note the third case: /kalender, /rekor, /aksesori and /target resolve to a
    // tab too, but are reached through in-page tab strips, so they are siblings
    // rather than pushes and must keep the brand mark.
    it.each([
        ['Runs/Show', '/aktivitas', 'Riwayat'],
        ['Pengaturan/ZonaHR', '/pengaturan', 'Pengaturan'],
    ])('replaces the brand mark with a back button on %s', (component, href, label) => {
        setMockPage({}, '/x', component);
        render(<MobileTopBar />);

        const back = screen.getByLabelText(`Kembali ke ${label}`);
        expect(back).toHaveAttribute('href', href);
        expect(screen.queryByLabelText('Beranda')).not.toBeInTheDocument();
    });

    // Pengaturan sits in this list, not the pushed one: it is one tap from the
    // Aku tab and from the avatar menu on every page, so it behaves as a root.
    it.each([
        'HariIni',
        'Koleksi/Kartu',
        'Riwayat/Jejak',
        'Aku',
        'Riwayat/Kalender',
        'Koleksi/Rekor',
        'Pengaturan/Index',
    ])(
        'keeps the brand mark and shows no back button on %s',
        (component) => {
            setMockPage({}, '/x', component);
            render(<MobileTopBar />);

            expect(screen.getByLabelText('Beranda')).toBeInTheDocument();
            expect(screen.queryByLabelText(/^Kembali ke/)).not.toBeInTheDocument();
        },
    );

    // A notification deep link opens the run detail cold, with nothing behind
    // it, so back has to be a real href rather than history.back().
    it('points back at a real url rather than relying on history', () => {
        setMockPage({}, '/aktivitas/123', 'Runs/Show');
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Kembali ke Riwayat').getAttribute('href')).toBe('/aktivitas');
    });

    it('shows the user menu when a user is signed in', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Buka menu Ada Lovelace')).toBeInTheDocument();
    });

    it('omits the user menu when there is no signed-in user', () => {
        setMockPage({ auth: { user: null } });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/Buka menu/)).not.toBeInTheDocument();
    });

    it('renders the Strava sync badge in its disconnected state by default', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Strava belum nyambung')).toBeInTheDocument();
    });

    // Installed as a PWA the page runs edge-to-edge, so this bar has to pad
    // itself past the notch or content slides under the status bar.
    it('pads the top by the safe-area inset so content clears the notch', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('pt-[max(0.75rem,env(safe-area-inset-top))]');
    });

    it('sticks to the top so content scrolls underneath it', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('sticky', 'top-0');
    });

    it('hides the hairline at rest and shows it once scrolled', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('border-transparent');

        act(() => {
            window.scrollY = 120;
            window.dispatchEvent(new Event('scroll'));
        });

        expect(container.querySelector('header')).toHaveClass('border-line');
        window.scrollY = 0;
    });

    it('keeps the cream ground', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('bg-cream-deep/85');
    });
});
