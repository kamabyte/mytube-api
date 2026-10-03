import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChannelSummary } from '@/types';

export function ChannelAvatar({
    channel,
    className,
}: {
    channel: Pick<ChannelSummary, 'name' | 'thumbnail'>;
    className?: string;
}) {
    return (
        <Avatar className={cn('size-9 bg-muted ring-1 ring-black/5 dark:ring-white/10', className)}>
            {channel.thumbnail && <AvatarImage src={channel.thumbnail} alt="" className="object-cover" />}
            <AvatarFallback className="bg-gradient-to-br from-zinc-500 to-zinc-700 text-[0.7em] font-semibold text-white">
                {initials(channel.name)}
            </AvatarFallback>
        </Avatar>
    );
}
