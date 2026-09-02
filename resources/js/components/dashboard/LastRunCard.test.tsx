import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import LastRunCard from './LastRunCard';

const richRun: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Negative-split morning',
    start_date_local: '2026-05-20T07:00',
    distance: 5280,
    elapsed_time: 2400,
    average_heartrate: 145,
    trimp_edwards: 87,
};

const bareRun: ActivityDetail = {
    id: 2,
    activity_id: 100,
    name: null,
    start_date_local: '2026-05-21T07:00',
    distance: 0,
    elapsed_time: 0,
    average_heartrate: null,
    trimp_edwards: null,
};

describe('LastRunCard', () => {
    it("renders the prototype's three mini rows", () => {
        render(<LastRunCard run={richRun} />);

        ['km', 'pace', 'trimp'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
        expect(screen.getByText('87')).toBeInTheDocument();
        expect(screen.getAllByText(/\/km$/).length).toBeGreaterThan(0);
    });

    it('uses em-dash placeholders for a run with no pace or TRIMP', () => {
        render(<LastRunCard run={bareRun} />);

        expect(screen.getAllByText('—')).toHaveLength(2);
    });

    it('links out to the activity detail page', () => {
        render(<LastRunCard run={richRun} />);

        expect(
            screen.getByRole('link', { name: /View run detail/ }),
        ).toHaveAttribute('href', '/activities/99');
    });

    it('dates the heading relative to now', () => {
        render(<LastRunCard run={richRun} />);

        expect(screen.getByText(/^Last run · /)).toBeInTheDocument();
    });
});
