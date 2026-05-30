import { Link } from "@inertiajs/react";
import { type MouseEventHandler, type ReactNode } from "react";
import { cn } from "@/lib/cn";
import {
  PADDING_CLASS,
  TONE_CLASS,
  type CardPadding,
  type CardTone,
} from "./Card";

interface LinkCardProps {
  href: string;
  /** Default 'cream'. */
  tone?: CardTone;
  /** Default 'md' — px-5 py-5. */
  padding?: CardPadding;
  onClick?: MouseEventHandler<Element>;
  className?: string;
  children: ReactNode;
}

const FOCUS_RING =
  "block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 focus-visible:ring-offset-cream";

export default function LinkCard({
  href,
  tone = "cream",
  padding = "md",
  onClick,
  className,
  children,
}: Readonly<LinkCardProps>) {
  return (
    <Link
      href={href}
      onClick={onClick}
      className={cn(
        TONE_CLASS[tone],
        PADDING_CLASS[padding],
        FOCUS_RING,
        className,
      )}
    >
      {children}
    </Link>
  );
}
