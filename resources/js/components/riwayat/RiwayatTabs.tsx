import { Link } from "@inertiajs/react";
import { Icon } from "@iconify/react";
import { cn } from "@/lib/cn";

export type RiwayatTab = "jejak" | "kalender";

interface RiwayatTabsProps {
  active: RiwayatTab;
  className?: string;
}

const TABS = [
  {
    id: "jejak" as const,
    label: "Jejak",
    href: "/aktivitas",
    icon: "mdi:arrow-top-right",
  },
  {
    id: "kalender" as const,
    label: "Kalender",
    href: "/kalender",
    icon: "mdi:calendar-blank-outline",
  },
];

export default function RiwayatTabs({
  active,
  className,
}: Readonly<RiwayatTabsProps>) {
  return (
    <nav
      aria-label="Sub-tab"
      className={cn("flex flex-wrap gap-1.5", className)}
    >
      {TABS.map((tab) => (
        <Link
          key={tab.id}
          href={tab.href}
          aria-current={active === tab.id ? "page" : undefined}
          className={cn(
            "inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-[13px] transition",
            active === tab.id
              ? "bg-sky text-cream font-semibold shadow-sm"
              : "bg-transparent text-ink-2 hover:bg-sky/[0.06]",
          )}
        >
          <Icon icon={tab.icon} width={14} height={14} aria-hidden />
          {tab.label}
        </Link>
      ))}
    </nav>
  );
}
