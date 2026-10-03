import EmptyPanel from '@/components/ui/EmptyPanel';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'EmptyPanel',
    description:
        'The empty state: an empty-tone card with a title, optional body and action, and Temari dozing when there is room.',
    usage: `<EmptyPanel face title="no runs yet" body="your first one lands here." />`,
    states: [
        {
            name: 'title only',
            render: () => <EmptyPanel title="nothing this month" />,
        },
        {
            name: 'with face and body',
            render: () => (
                <EmptyPanel
                    face
                    title="no runs yet"
                    body="sync Strava and your first one lands here."
                />
            ),
        },
        {
            name: 'horizontal with action',
            render: () => (
                <EmptyPanel
                    face
                    layout="horizontal"
                    title="no plan yet"
                    body="set a race and temari builds the weeks."
                    action={
                        <PillButton tone="outline" size="sm" className="mt-3">
                            set a race
                        </PillButton>
                    }
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
