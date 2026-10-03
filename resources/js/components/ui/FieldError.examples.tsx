import FieldError from '@/components/ui/FieldError';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'FieldError',
    description:
        "A form field's validation message, announced as an alert. Renders nothing without a message.",
    usage: `<FieldError message={errors.race_date} />`,
    states: [
        {
            name: 'with a message',
            render: () => <FieldError message="pick a race day after today." />,
        },
        { name: 'no message', render: () => <FieldError message={null} /> },
    ],
} satisfies CatalogueEntry;
