import { cn } from '@/lib/utils';

export function LogoMark({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex size-8 items-center justify-center rounded-[10px] bg-gradient-to-br from-[#ff6b5e] to-[#e0241b] shadow-sm shadow-red-900/30',
                className,
            )}
            aria-hidden
        >
            <svg viewBox="0 0 24 24" className="size-4 translate-x-px fill-white">
                <path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14Z" />
            </svg>
        </span>
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
