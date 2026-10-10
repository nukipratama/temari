import AiOutageBanner from '@/components/AiOutageBanner';
import HistoryHeader from '@/components/history/HistoryHeader';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, { SkeletonRows } from '@/components/ui/Skeleton';

export function FeedRunsSkeleton() {
    return <SkeletonRows count={4} className="mt-8" />;
}

export function ConsistencyLineSkeleton() {
    return <Skeleton className="mx-auto mb-3 h-3 w-56" />;
}

export function MonthRecapSkeleton() {
    return <Skeleton className="mb-2.5 h-16 w-full" />;
}

export function CalendarGridSkeleton() {
    return <SkeletonRows count={6} />;
}

function CalendarSkeleton() {
    return (
        <>
            <div className="mt-8 mb-2.5 flex items-center justify-between gap-2">
                <Skeleton className="size-7 rounded-full" />
                <Skeleton className="h-4 w-28" />
                <Skeleton className="size-7 rounded-full" />
            </div>
            <ConsistencyLineSkeleton />
            <MonthRecapSkeleton />
            <Skeleton className="mt-3 mb-3 h-17 min-[900px]:h-11.5" />
            <div className="rounded-md border border-border p-3">
                <CalendarGridSkeleton />
            </div>
        </>
    );
}

export default function HistorySkeleton({ href }: Readonly<{ href: string }>) {
    const query = href.split('?')[1] ?? '';
    const calendar = new URLSearchParams(query).get('view') === 'calendar';

    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                <HistoryHeader active={calendar ? 'calendar' : 'feed'} />
                {calendar ? <CalendarSkeleton /> : <FeedRunsSkeleton />}
            </PageContainer>
        </>
    );
}
