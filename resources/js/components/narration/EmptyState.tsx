import { DatabaseX } from 'lucide-react';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';

export default function EmptyState() {
    return (
        <Card tone="empty" padding="hero" className="mt-4 text-center">
            <Icon
                icon={DatabaseX}
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
