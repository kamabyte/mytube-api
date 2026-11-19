import { cn } from '@/lib/utils';

export function LogoMark({ className }: { className?: string }) {
    return (
        <img
            src="/icons/icon-192.png"
            alt=""
            width={32}
            height={32}
            className={cn('inline-block size-8 shrink-0 rounded-[10px] shadow-sm shadow-red-900/30', className)}
            aria-hidden
        />
    );
}

export function Logo({ className }: { className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-2.5', className)}>
            <LogoMark />
            <span className="font-display text-[19px] font-semibold tracking-tight">MyTube</span>
        </span>
    );
}
