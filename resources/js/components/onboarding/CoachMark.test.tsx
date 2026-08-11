import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useRef } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import CoachMark, { type CoachMarkPlacement } from './CoachMark';

function Harness({
    id = 'welcome-tile',
    placement,
}: Readonly<{ id?: string; placement?: CoachMarkPlacement }>) {
    const anchorRef = useRef<HTMLButtonElement>(null);
    return (
        <div>
            <button ref={anchorRef}>Anchor</button>
            <CoachMark
                id={id}
                anchorRef={anchorRef}
                placement={placement}
                title="This is new"
                body="Explains the thing."
            />
        </div>
    );
}

beforeEach(() => {
    window.localStorage.clear();
    setMockPage({ auth: { user: makeUser({ id: 3 }) } });
    // Ensure rAF runs synchronously so the initial position lands in the same render.
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
        cb(performance.now());
        return 1;
    });
    vi.stubGlobal('cancelAnimationFrame', () => {});
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('CoachMark', () => {
    it('renders the title and body anchored near the target element', () => {
        render(<Harness />);

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('This is new')).toBeInTheDocument();
        expect(screen.getByText('Explains the thing.')).toBeInTheDocument();
    });

    it('dismisses on clicking "Got it" and stays dismissed on remount', () => {
        const { unmount } = render(<Harness />);

        fireEvent.click(screen.getByRole('button', { name: 'Got it' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        unmount();
        render(<Harness />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('dismisses on Escape', async () => {
        render(<Harness />);

        fireEvent.keyDown(document, { key: 'Escape' });

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('dismisses on a pointerdown outside the coach-mark', async () => {
        render(
            <div>
                <Harness />
                <div data-testid="outside">outside</div>
            </div>,
        );

        fireEvent.pointerDown(screen.getByTestId('outside'));

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('stays hidden while its anchor is off screen', () => {
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                observe = vi.fn();
                unobserve = vi.fn();
                disconnect = vi.fn();
                takeRecords = vi.fn(() => []);
            },
        );

        render(<Harness />);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps itself inside the viewport whatever the placement asks for', () => {
        render(<Harness placement="left" />);

        const dialog = screen.getByRole('dialog');
        expect(dialog.style.left).toBe('12px');
        expect(dialog.style.top).toBe('12px');
    });

    it('renders nothing once already dismissed for this user', () => {
        window.localStorage.setItem('temari:coachmark:3:welcome-tile', '1');

        render(<Harness />);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('renders nothing for a different coach-mark id sharing the page', () => {
        window.localStorage.setItem('temari:coachmark:3:welcome-tile', '1');

        render(<Harness id="another-tile" />);

        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
