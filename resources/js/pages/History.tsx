import type { ComponentProps } from 'react';

import { appLayout } from '@/layouts/appLayout';

import Calendar from './Activities/Calendar';
import Feed from './Activities/Feed';

type HistoryProps =
    | ({ activeView: 'list' } & ComponentProps<typeof Feed>)
    | ({ activeView: 'calendar' } & ComponentProps<typeof Calendar>);

/**
 * /history — merges the former /activities (list) and /calendar destinations
 * behind one route. `HistoryController` builds only the active view's props
 * server-side, so this just switches which existing page component renders;
 * neither Feed nor Calendar's own markup/chrome changes.
 */
export default function History(props: Readonly<HistoryProps>) {
    return props.activeView === 'calendar' ? (
        <Calendar {...props} />
    ) : (
        <Feed {...props} />
    );
}

History.layout = appLayout;
