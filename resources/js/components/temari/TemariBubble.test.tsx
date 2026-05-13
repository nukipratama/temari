import { render, screen } from '@testing-library/react';
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

    it('renders all variations inline as alt-takes (no longer cycle-on-tap)', () => {
        render(
            <TemariBubble
                line={makeLine({ speech: 'primary speech' })}
                variations={['alt one', 'alt two']}
            />,
        );
        expect(screen.getByText('primary speech')).toBeInTheDocument();
        expect(screen.getByText('alt one')).toBeInTheDocument();
        expect(screen.getByText('alt two')).toBeInTheDocument();
    });

    it('deduplicates a variation that matches the primary line', () => {
        render(
            <TemariBubble
                line={makeLine({ speech: 'same line' })}
                variations={['same line', 'different']}
            />,
        );
        expect(screen.getAllByText('same line')).toHaveLength(1);
        expect(screen.getByText('different')).toBeInTheDocument();
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
