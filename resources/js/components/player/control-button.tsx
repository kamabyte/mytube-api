import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Круглая кнопка панели плеера; подпись — и для скринридера, и подсказкой. */
export function ControlButton({ label, className, ...props }: ComponentProps<'button'> & { label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cn(
                'inline-flex size-10 shrink-0 items-center justify-center rounded-full text-white/90 transition-colors outline-none hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-white/60 disabled:opacity-40 [&_svg]:size-5',
                className,
            )}
            {...props}
        />
    );
}
