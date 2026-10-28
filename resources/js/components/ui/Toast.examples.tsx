import { ToastMessage } from '@/components/ui/Toast';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'Toast',
    description:
        "The shell's transient confirmation, for an action that is otherwise silent. It sits above the nav pill, clears itself after about 4s and is announced politely. Banners and inline saved flashes stay as they are.",
    usage: `onSuccess: () => showToast(\`moved to \${weekdayLabel(toDate)}\`)`,
    states: [
        {
            name: 'moved',
            render: () => <ToastMessage>moved to Thu</ToastMessage>,
        },
        {
            name: 'short',
            render: () => <ToastMessage>skipped</ToastMessage>,
        },
    ],
} satisfies CatalogueEntry;
