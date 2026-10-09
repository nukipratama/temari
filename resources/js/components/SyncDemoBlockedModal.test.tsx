import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import SyncDemoBlockedModal, {
    SYNC_DEMO_BLOCKED,
} from './SyncDemoBlockedModal';

describe('SyncDemoBlockedModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(
            <SyncDemoBlockedModal open={false} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('is a dialog named by the sync copy', async () => {
        render(<SyncDemoBlockedModal open onClose={vi.fn()} />);

        expect(
            await screen.findByRole('dialog', {
                name: SYNC_DEMO_BLOCKED.title,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(SYNC_DEMO_BLOCKED.body)).toBeInTheDocument();
    });

    it('calls onClose when dismissed', async () => {
        const onClose = vi.fn();
        render(<SyncDemoBlockedModal open onClose={onClose} />);
        await screen.findByRole('dialog');

        fireEvent.click(screen.getByRole('button', { name: 'not now' }));

        expect(onClose).toHaveBeenCalledOnce();
    });
});
