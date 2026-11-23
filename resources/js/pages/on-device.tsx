import { Head, usePage } from '@inertiajs/react';
import { DeviceLibrary } from '@/components/device-library';

export default function OnDevice() {
    const { url } = usePage();
    return (
        <div className="mx-auto max-w-4xl">
            <Head title="На устройстве" />
            <DeviceLibrary key={url} />
        </div>
    );
}
