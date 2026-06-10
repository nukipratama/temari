import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MilestoneBanner, { type PendingMilestone } from './MilestoneBanner';

// The dismiss endpoint returns plain JSON, so the banner posts via fetch
// (not Inertia's router). Stub it so dismissing in any test stays inert.
const fetchMock = vi.fn(() => Promise.resolve(new Response('{"ok":true}', { status: 200 })));
beforeEach(() => {
    fetchMock.mockClear();
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    vi.unstubAllGlobals();
});

describe('MilestoneBanner', () => {
    it('renders nothing when pending is null', () => {
        const { container } = render(<MilestoneBanner pending={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the highest-priority milestone (first in the array)', () => {
        const pending: PendingMilestone = {
            activity_id: 42,
            milestones: [
                { kind: 'pr', label: 'Personal Record!', body: 'Lo bikin PR baru di 5km.' },
            ],
        };
        render(<MilestoneBanner pending={pending} />);
        expect(screen.getByText('Personal Record!')).toBeInTheDocument();
        expect(screen.getByText('Lo bikin PR baru di 5km.')).toBeInTheDocument();
    });

    it('shows a "+ N lain" footnote when multiple milestones land at once and expands on click', () => {
        const pending: PendingMilestone = {
            activity_id: 7,
            milestones: [
                { kind: 'pr', label: 'PR!', body: 'PR di 10km.' },
                { kind: 'longest_ever', label: 'Lari terjauh', body: '10.5 km terjauh.' },
                { kind: 'first_ever_pace', label: 'Sub-5 pertama', body: 'Pace pertama di bawah 5.' },
            ],
        };
        render(<MilestoneBanner pending={pending} />);
        const expand = screen.getByRole('button', { name: /\+ 2 milestone lain/i });
        fireEvent.click(expand);
        expect(screen.getByText('Lari terjauh')).toBeInTheDocument();
        expect(screen.getByText('Sub-5 pertama')).toBeInTheDocument();
    });

    it.each(['pr', 'longest_ever', 'first_ever_distance', 'first_ever_pace'] as const)(
        'renders the banner for the %s milestone kind',
        (kind) => {
            const pending: PendingMilestone = {
                activity_id: 1,
                milestones: [{ kind, label: `${kind} label`, body: `${kind} body` }],
            };
            render(<MilestoneBanner pending={pending} />);
            // Each kind hits a different switch branch in iconFor + iconBgFor;
            // visible content proves the branch ran without throwing.
            expect(screen.getByText(`${kind} label`)).toBeInTheDocument();
            expect(screen.getByText(`${kind} body`)).toBeInTheDocument();
        },
    );

    it('POSTs to the dismiss endpoint via fetch and hides itself when "Tutup" is clicked', async () => {
        const pending: PendingMilestone = {
            activity_id: 99,
            milestones: [{ kind: 'pr', label: 'PR!', body: 'x' }],
        };
        render(<MilestoneBanner pending={pending} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/milestones/99/dismiss',
            expect.objectContaining({ method: 'POST' }),
        );
        await waitFor(() => expect(screen.queryByText('PR!')).not.toBeInTheDocument());
    });
});
