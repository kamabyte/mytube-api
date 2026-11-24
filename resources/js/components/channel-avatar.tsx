import { Clock3 } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChannelSummary } from '@/types';

export function ChannelAvatar({
    channel,
    className,
}: {
    channel: Pick<ChannelSummary, 'name' | 'thumbnail' | 'is_watch_later'>;
    className?: string;
}) {
    return (
        <Avatar className={cn('size-9 bg-muted ring-1 ring-black/5 dark:ring-white/10', className)}>
            {channel.thumbnail && <AvatarImage src={channel.thumbnail} alt="" className="object-cover" />}
            <AvatarFallback className={cn(
                'bg-gradient-to-br text-[0.7em] font-semibold text-white',
                channel.is_watch_later ? 'from-indigo-500 to-violet-700' : 'from-zinc-500 to-zinc-700',
            )}>
                {channel.is_watch_later ? <Clock3 className="size-1/2" aria-hidden /> : initials(channel.name)}
            </AvatarFallback>
        </Avatar>
    );
}
