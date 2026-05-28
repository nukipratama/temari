import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import GuidedTour, { type TourStep } from './GuidedTour';

const steps: TourStep[] = [
    { target: 'greeting', title: 'Langkah pertama', body: 'Ini briefing harian kamu.', tipSide: 'below' },
    { target: 'kartu-strip', title: 'Kartu dari lari', body: 'Tiap lari dapat kartu.', tipSide: 'above' },
];

function mountTarget(dataAttr: string) {
    const el = document.createElement('div');
    el.dataset['tour'] = dataAttr;
    el.getBoundingClientRect = () => ({ top: 100, left: 50, width: 200, height: 40, right: 250, bottom: 140, x: 50, y: 100, toJSON: () => ({}) });
    document.body.appendChild(el);
    return el;
}

describe('GuidedTour', () => {
    let targets: HTMLElement[] = [];

    beforeEach(() => {
        localStorage.clear();
        targets = steps.map((s) => mountTarget(s.target));
    });

    afterEach(() => {
        targets.forEach((el) => el.remove());
        targets = [];
    });

    it('renders the first step title and body', async () => {
        await act(async () => {
            render(<GuidedTour steps={steps} storageKey="tour_test" forceShow />);
        });
        expect(screen.getByText('Langkah pertama')).toBeInTheDocument();
        expect(screen.getByText(/briefing harian/)).toBeInTheDocument();
    });

    it('advances to the next step when Lanjut is clicked', async () => {
        await act(async () => {
            render(<GuidedTour steps={steps} storageKey="tour_test" forceShow />);
        });
        await act(async () => { fireEvent.click(screen.getByText('Lanjut →')); });
        expect(screen.getByText('Kartu dari lari')).toBeInTheDocument();
    });

    it('dismisses on the last step and writes localStorage', async () => {
        await act(async () => {
            render(<GuidedTour steps={steps} storageKey="tour_test" />);
        });
        // Advance to last step
        await act(async () => { fireEvent.click(screen.getByText('Lanjut →')); });
        // Finish
        await act(async () => { fireEvent.click(screen.getByText('Selesai')); });
        expect(screen.queryByText('Langkah pertama')).not.toBeInTheDocument();
        expect(localStorage.getItem('tour_test')).toBe('true');
    });

    it('dismisses when × is clicked and writes localStorage', async () => {
        await act(async () => {
            render(<GuidedTour steps={steps} storageKey="tour_test" />);
        });
        await act(async () => { fireEvent.click(screen.getByText('Lewati')); });
        expect(screen.queryByText('Langkah pertama')).not.toBeInTheDocument();
        expect(localStorage.getItem('tour_test')).toBe('true');
    });

    it('does not write localStorage when forceShow is true', async () => {
        await act(async () => {
            render(<GuidedTour steps={steps} storageKey="tour_test" forceShow />);
        });
        await act(async () => { fireEvent.click(screen.getByText('Lewati')); });
        expect(localStorage.getItem('tour_test')).toBeNull();
    });

    it('renders nothing when localStorage flag is set (tour already seen)', async () => {
        localStorage.setItem('tour_test', 'true');
        const { container } = render(<GuidedTour steps={steps} storageKey="tour_test" />);
        expect(container.firstChild).toBeNull();
    });
});
