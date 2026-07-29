import { Icon } from '@iconify/react';
import Card from '@/components/ui/Card';

export default function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="mt-4 text-center">
            <Icon icon="mdi:database-off" width={32} className="mx-auto text-ink-3" aria-hidden />
            <p className="mt-2 text-sm text-ink-2">Belum ada catatan token di rentang ini.</p>
        </Card>
    );
}
