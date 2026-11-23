import { router } from '@inertiajs/react';
import type { ComponentPropsWithRef } from 'react';
import { DEVICE_LIBRARY } from '@/lib/offline-video';
import type { SharedProps } from '@/types';

export function DeviceLibraryLink({ href = DEVICE_LIBRARY, onClick, ...props }: ComponentPropsWithRef<'a'>) {
    return (
        <a
            {...props}
            href={href}
            onClick={(event) => {
                onClick?.(event);
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

                event.preventDefault();
                router.push<SharedProps>({
                    url: href,
                    component: 'on-device',
                    props: ({ sidebarChannels, unreadNotifications, deletePin, downloads }) => ({
                        sidebarChannels, unreadNotifications, deletePin, downloads,
                    }),
                });
            }}
        />
    );
}
