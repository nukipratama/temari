import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import MoodChip from './MoodChip';

describe('MoodChip', () => {
    it.each([
        'nyala',
        'enteng',
        'lemes',
        'oleng',
        'mumet',
        'adem',
    ] satisfies Mood[])('renders default label for mood %s', (mood) => {
        const expected = {
            nyala: 'Blazing',
            enteng: 'Easy',
            lemes: 'Gassed',
            oleng: 'Wobbly',
            mumet: 'Overloaded',
            adem: 'Chill',
        }[mood];
        render(<MoodChip mood={mood} />);
        expect(screen.getByText(expected)).toBeInTheDocument();
    });

    it('honours an explicit label override', () => {
        render(<MoodChip mood="nyala" label="Custom" />);
        expect(screen.getByText('Custom')).toBeInTheDocument();
    });
});
