import { Link } from '@inertiajs/react';

const sections = [
    { href: '/videos', label: 'Видео' },
    { href: '/channels', label: 'Каналы' },
] as const;

export function CatalogSwitch({ current }: { current: '/videos' | '/channels' }) {
    return (
        <nav aria-label="Разделы каталога" className="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-muted p-1 md:hidden">
            {sections.map(({ href, label }) => (
                <Link key={href} href={href} aria-current={href === current ? 'page' : undefined}
                    className="pressable grid min-h-11 place-items-center rounded-lg text-sm font-medium text-muted-foreground aria-[current=page]:bg-background aria-[current=page]:text-foreground aria-[current=page]:shadow-sm">
                    {label}
                </Link>
            ))}
        </nav>
    );
}
