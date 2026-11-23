import { CircleCheck, Download, HardDriveDownload, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DeviceLibraryLink } from '@/components/device-library-link';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { cancelOfflineDownload, startOfflineDownload, useOfflineDownload } from '@/lib/offline-downloads';
import { DEVICE_LIBRARY, savedVideo, supportsOfflineVideo } from '@/lib/offline-video';
import type { Video, VideoDetail } from '@/types';

export function VideoDownloadItems({ video, streamUrl }: { video: Video | VideoDetail; streamUrl: string | null }) {
    const supported = supportsOfflineVideo();
    const saving = useOfflineDownload(video.id);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        if (!supported || saving) return;
        let current = true;
        void savedVideo(video.id).then((entry) => {
            if (current) setSaved(Boolean(entry));
        }).catch(() => {});
        return () => { current = false; };
    }, [supported, saving, video.id]);

    if (!streamUrl && !saving && !saved) return null;

    return (
        <>
            {saving ? (
                <DropdownMenuItem disabled={saving.cancelling} onSelect={() => cancelOfflineDownload(video.id)} className="min-h-11">
                    <X />
                    {saving.cancelling ? 'Отменяем…' : 'Отменить сохранение для офлайна'}
                </DropdownMenuItem>
            ) : saved ? (
                <DropdownMenuItem asChild className="min-h-11">
                    <DeviceLibraryLink href={`${DEVICE_LIBRARY}?video=${video.id}`}>
                        <CircleCheck />
                        Смотреть офлайн
                    </DeviceLibraryLink>
                </DropdownMenuItem>
            ) : supported && streamUrl && (
                <DropdownMenuItem onSelect={() => void startOfflineDownload(video)} className="min-h-11">
                    <HardDriveDownload />
                    <span>
                        Сохранить для офлайна
                        <span className="block text-xs text-muted-foreground">В разделе «Офлайн»</span>
                    </span>
                </DropdownMenuItem>
            )}
            {streamUrl && (
                <DropdownMenuItem asChild className="min-h-11">
                    <a href={`${streamUrl}?download=1`} download>
                        <Download />
                        <span>
                            Скачать MP4
                            <span className="block text-xs text-muted-foreground">Отдельным файлом</span>
                        </span>
                    </a>
                </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
        </>
    );
}
