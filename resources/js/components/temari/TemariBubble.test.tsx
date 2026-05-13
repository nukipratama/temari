import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import TemariBubble from './TemariBubble';
import type { StoryLine } from '@/types/inertia';

function makeLine(overrides: Partial<StoryLine> = {}): StoryLine {
    return {
        id: 1,
        user_id: 1,
        activity_id: 1,
        kind: 'post_run',
        mood: 'bouncy',
        speech: 'default speech',
        sigil_pattern: 'orct',
        for_date: null,
        ...overrides,
    };
}

describe('TemariBubble', () => {
    it('renders default fallback speech when line is null', () => {
        render(<TemariBubble line={null} />);
        expect(screen.getByText(/belum punya cerita/i)).toBeInTheDocument();
    });

    it('renders the line speech when no variations', () => {
        render(<TemariBubble line={makeLine({ speech: 'static speech' })} variations={[]} />);
        expect(screen.getByText('static speech')).toBeInTheDocument();
    });

    it('cycles through variations on click', async () => {
        const user = userEvent.setup();
        render(<TemariBubble line={makeLine()} variations={['first', 'second', 'third']} />);
        expect(screen.getByText('first')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /ganti kata temari/i }));
        expect(screen.getByText('second')).toBeInTheDocument();
        await user.click(screen.getByRole('button'));
        expect(screen.getByText('third')).toBeInTheDocument();
        // wraps back to first
        await user.click(screen.getByRole('button'));
        expect(screen.getByText('first')).toBeInTheDocument();
    });

    it('renders sm size variant', () => {
        const { container } = render(<TemariBubble line={makeLine()} size="sm" />);
        expect(container.querySelector('.h-14')).toBeTruthy();
    });

    it('renders accessory glyph when provided', () => {
        const { container } = render(<TemariBubble line={makeLine()} accessory="headband" />);
        expect(container.querySelector('svg')).toBeTruthy();
    });
});
