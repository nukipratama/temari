import { Link, router } from "@inertiajs/react";
import { Icon } from "@iconify/react";
import { useCallback, useRef, useState } from "react";
import UserAvatar from "@/components/UserAvatar";
import { useDismissable } from "@/hooks/useDismissable";
import { useFocusReturn } from "@/hooks/useFocusReturn";

/** Shared by both menu items so they read as one list. */
const ITEM_CLASS =
  "pressable flex w-full items-center gap-2.5 px-4 py-2.5 text-left font-sans text-sm text-ink transition hover:bg-cream-deep";

/**
 * Avatar button that opens a dropdown with the signed-in name, a link to
 * Pengaturan, and logout. Shared by the desktop `TopNav` and the mobile
 * `MobileTopBar`, so both account actions are one tap from every page on every
 * layout — settings used to be a row buried at the bottom of Aku, which meant
 * leaving whatever you were doing to reach it.
 *
 * Deliberately NOT an ARIA menu: it is a disclosure popover, so the items stay
 * a plain Link and button rather than gaining `role="menuitem"`. A test pins
 * this.
 */
export default function UserMenu({
  name,
  avatarUrl,
}: Readonly<{ name: string; avatarUrl: string | null }>) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const close = useCallback(() => setOpen(false), []);
  useDismissable(open, containerRef, close);
  useFocusReturn(open);

  function handleLogout() {
    setOpen(false);
    router.post("/logout");
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-label={`Buka menu ${name}`}
        className="flex h-11 w-11 items-center justify-center rounded-full ring-2 ring-cream-deep transition hover:ring-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 focus-visible:ring-offset-cream"
      >
        <UserAvatar name={name} avatarUrl={avatarUrl} size="md" className="h-11 w-11" />
      </button>
      {open && (
        <div className="absolute right-0 top-[calc(100%+10px)] z-40 w-52 overflow-hidden rounded-2xl border border-cream-deep bg-cream shadow-lg">
          <div className="border-b border-cream-deep px-4 py-3">
            <div className="text-label-micro font-semibold text-ink-3">
              Masuk sebagai
            </div>
            <div className="mt-0.5 truncate font-sans text-sm font-medium text-ink">
              {name}
            </div>
          </div>
          <Link href="/pengaturan" onClick={close} className={ITEM_CLASS}>
            <Icon
              icon="mdi:cog-outline"
              width={16}
              height={16}
              aria-hidden
              className="text-ink-3"
            />
            Pengaturan
          </Link>
          <button type="button" onClick={handleLogout} className={ITEM_CLASS}>
            <Icon
              icon="mdi:logout"
              width={16}
              height={16}
              aria-hidden
              className="text-ink-3"
            />
            Keluar
          </button>
        </div>
      )}
    </div>
  );
}
