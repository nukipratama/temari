import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MoodChip from './MoodChip';
import type { Mood } from '@/types/inertia';

describe('MoodChip', () => {
    it.each(['nyala', 'enteng', 'lemes', 'oleng', 'mumet', 'adem'] satisfies Mood[])(
        'renders default Bahasa label for mood %s',
        (mood) => {
            const expected = { nyala: 'Nyala', enteng: 'Enteng', lemes: 'Lemes', oleng: 'Oleng', mumet: 'Mumet', adem: 'Adem' }[mood];
            render(<MoodChip mood={mood} />);
            expect(screen.getByText(expected)).toBeInTheDocument();
        },
    );

    it('honours an explicit label override', () => {
        render(<MoodChip mood="nyala" label="Custom" />);
        expect(screen.getByText('Custom')).toBeInTheDocument();
    });
});
