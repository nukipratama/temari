import HistoryNav, { type HistoryTab } from '@/components/history/HistoryNav';
import PageHero from '@/components/ui/PageHero';

interface HistoryHeaderProps {
    active: HistoryTab;
    /** Lifetime activity count for the eyebrow; omitted while unknown. */
    activityCount?: number;
}

/**
 * History's top fold. The prototype draws one header above its feed/calendar
 * switch, so both `?view=` halves render this rather than each composing an
 * eyebrow and headline of its own.
 */
export default function HistoryHeader({
    active,
    activityCount,
}: Readonly<HistoryHeaderProps>) {
    return (
        <header className="flex flex-col gap-5">
            <PageHero
                eyebrow={
                    activityCount === undefined
                        ? 'History'
                        : `History · ${activityCount} activities`
                }
                size="quote-lg"
                italic
            >
                every run
                <br />
                <em className="text-horizon-ink">has a story.</em>
            </PageHero>
            <HistoryNav active={active} />
        </header>
    );
}
