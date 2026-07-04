import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Login from './Login';
import { setMockPage } from '@/test/setup';

describe('Login', () => {
    it('shows the Strava CTA with the given URL', () => {
        render(<Login authStravaUrl="/auth/strava/redirect" />);
        const strava = screen.getByText(/Sambungkan dengan Strava/).closest('a');
        expect(strava?.getAttribute('href')).toBe('/auth/strava/redirect');
    });

    it('appends the deep-link ?from to the Strava CTA when present', () => {
        render(<Login authStravaUrl="/auth/strava/redirect" from="/aktivitas/5?tab=splits" />);
        const strava = screen.getByText(/Sambungkan dengan Strava/).closest('a');
        expect(strava?.getAttribute('href')).toBe(
            '/auth/strava/redirect?from=' + encodeURIComponent('/aktivitas/5?tab=splits'),
        );
    });

    it('hides demo button when demoLoginEnabled is false', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.queryByText('Coba versi demo')).not.toBeInTheDocument();
    });

    it('shows demo button when demoLoginEnabled is true', () => {
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('Coba versi demo')).toBeInTheDocument();
    });

    it('renders the brand hero + 3 onboarding pillars in Temari first-person voice', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
        // Mascot intro headline includes the value-prop CTA.
        expect(screen.getByText(/Gak Sendirian/)).toBeInTheDocument();
        expect(screen.getByText(/Halo, aku Temari/)).toBeInTheDocument();
        [/Aku baca/, /Aku catat/, /Aku temenin/].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
    });

    it('renders the intro video hero with poster + play overlay', () => {
        const { container } = render(<Login authStravaUrl="/x" />);
        const video = container.querySelector('video');
        expect(video?.getAttribute('src')).toBe('/videos/intro.mp4');
        expect(video?.getAttribute('poster')).toBe('/videos/intro-poster.jpg');
        expect(screen.getByLabelText('Putar video intro')).toBeInTheDocument();
    });

    it('clicking play starts the intro and hides the overlay', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        // jsdom does not implement media playback — stub play() so the handler runs.
        const playSpy = vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue();
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getByLabelText('Putar video intro'));
        expect(playSpy).toHaveBeenCalled();
        expect(screen.queryByLabelText('Putar video intro')).not.toBeInTheDocument();
        playSpy.mockRestore();
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getByText('Coba versi demo'));
    });

    it('shows a real sample Kartu as concrete proof of the product', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('Ini kartu beneran, bukan mockup')).toBeInTheDocument();
        expect(screen.getByRole('img', { name: '10K Subuh' })).toBeInTheDocument();
    });
});
