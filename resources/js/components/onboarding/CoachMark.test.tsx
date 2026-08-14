import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useRef } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage, stubSyncAnimationFrame } from '@/test/setup';

import CoachMark, {
    type CoachMarkPlacement,
    type Obstacle,
    positionFor,
} from './CoachMark';

function rect(
    left: number,
    top: number,
    width: number,
    height: number,
): DOMRect {
    return {
        left,
        top,
        width,
        height,
        right: left + width,
        bottom: top + height,
        x: left,
        y: top,
        toJSON: () => ({}),
    } as DOMRect;
}

function box(
    left: number,
    top: number,
    width: number,
    height: number,
): Obstacle {
    return { left, top, right: left + width, bottom: top + height };
}

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
    stubSyncAnimationFrame();
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

    it('closes on Escape but does not persist as dismissed', async () => {
        const { unmount } = render(<Harness />);

        fireEvent.keyDown(document, { key: 'Escape' });

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            window.localStorage.getItem('temari:coachmark:3:welcome-tile'),
        ).toBeNull();

        unmount();
        render(<Harness />);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('closes on a pointerdown outside the coach-mark but does not persist as dismissed', async () => {
        const { unmount } = render(
            <div>
                <Harness />
                <div data-testid="outside">outside</div>
            </div>,
        );

        fireEvent.pointerDown(screen.getByTestId('outside'));

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            window.localStorage.getItem('temari:coachmark:3:welcome-tile'),
        ).toBeNull();

        unmount();
        render(<Harness />);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
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

describe('positionFor', () => {
    // jsdom reports 1024x768.
    const anchor = rect(300, 300, 200, 100);

    it('honours the requested placement when nothing is in the way', () => {
        const style = positionFor(anchor, 'bottom', 120);

        expect(style.top).toBe(412);
    });

    it('flips away from a placement that would bury a control', () => {
        // Directly below the anchor, fully under a 120px-tall mark placed there.
        const belowTheAnchor = box(340, 420, 100, 40);

        const style = positionFor(anchor, 'bottom', 120, [belowTheAnchor]);

        expect(style.top).not.toBe(412);
    });

    it('prefers burying nothing over the smaller total overlap', () => {
        // "bottom" fully buries one small tab while merely clipping the panels;
        // total-area scoring would pick it, burying the tab.
        const tab = box(340, 420, 100, 40);
        const panels = [box(0, 100, 1024, 100), box(0, 200, 1024, 100)];

        const style = positionFor(anchor, 'bottom', 120, [tab, ...panels]);

        const left = Number(style.left);
        const top = Number(style.top);
        const coversTab =
            left < tab.right &&
            left + 256 > tab.left &&
            top < tab.bottom &&
            top + 120 > tab.top;
        expect(coversTab).toBe(false);
    });

    it('falls back to the requested placement when every candidate collides', () => {
        const everywhere = box(0, 0, 1024, 768);

        const style = positionFor(anchor, 'bottom', 120, [everywhere]);

        expect(style.top).toBe(412);
    });

    it('never positions itself outside the viewport', () => {
        const offScreen = rect(-400, -400, 50, 50);

        const style = positionFor(offScreen, 'left', 120);

        expect(style.left).toBe(12);
        expect(style.top).toBe(12);
    });
});
