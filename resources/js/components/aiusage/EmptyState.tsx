import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

export default function EmptyState() {
    return (
        <Card tone="empty" padding="hero" className="mt-4 text-center">
            <Icon
                icon="mdi:database-off"
                width={32}
                className="mx-auto text-text-3"
                aria-hidden
            />
            <p className="mt-2 text-sm text-text-2">
                No token usage recorded in this range yet.
            </p>
        </Card>
    );
}
