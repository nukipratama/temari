import PushNotificationToggle from '@/components/PushNotificationToggle';
import Chip from '@/components/ui/Chip';
import { TelegramIcon } from '@/components/ui/Icon';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SettingsRow from '@/components/ui/SettingsRow';

import BackButton from './BackButton';

export default function NudgeStep({
    telegramConnectUrl,
    processing,
    onBack,
    onFinish,
}: Readonly<{
    telegramConnectUrl: string | null;
    processing: boolean;
    onBack: () => void;
    onFinish: () => void;
}>) {
    return (
        <div key="nudge" className="reveal">
            <div className="mb-5 flex h-11 items-center justify-between">
                <BackButton onClick={onBack} />
                <PillButton
                    tone="ghost"
                    disabled={processing}
                    onClick={onFinish}
                >
                    skip for now
                </PillButton>
            </div>

            <div className="flex items-center gap-2">
                <PageHero size="quote-lg" italic>
                    want temari to <br />
                    <em className="text-icon-accent">nudge you?</em>
                </PageHero>
                <Chip className="mt-1 self-start">optional</Chip>
            </div>
            <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                she only pings you when there is something to say: the read on a
                run once it lands, your week and month wrapped up, a heads-up
                when a streak is about to slip, and a word if Strava quietly
                stops syncing.
            </p>

            <div className="mt-6 flex flex-col divide-y divide-dashed divide-border">
                {telegramConnectUrl !== null && (
                    <SettingsRow
                        icon={TelegramIcon}
                        label="Telegram"
                        description="connect it so temari can keep you posted."
                        externalHref={telegramConnectUrl}
                        openInNewTab
                    />
                )}
                <PushNotificationToggle />
            </div>

            <p className="mt-3 font-sans text-xs leading-relaxed text-text-3">
                you can wire either of these up later from settings.
            </p>

            <PillButton
                tone="horizon"
                disabled={processing}
                onClick={onFinish}
                className="mt-4 w-full justify-center"
            >
                {processing ? 'saving…' : 'finish'}
            </PillButton>
        </div>
    );
}
