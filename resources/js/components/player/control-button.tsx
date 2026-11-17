import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Кнопка внутри группы контролов; подпись — и для скринридера, и подсказкой. */
export function ControlButton({ label, className, ...props }: ComponentProps<'button'> & { label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-full text-white transition-[background-color,transform] outline-none hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white/80 active:scale-95 disabled:opacity-40 @md:size-11 [&_svg]:size-5 [&_svg]:stroke-[2.25] @md:[&_svg]:size-6',
                className,
            )}
            {...props}
        />
    );
}
