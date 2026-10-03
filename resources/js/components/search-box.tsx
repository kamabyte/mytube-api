import { router, usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

function currentQuery(url: string): string {
    return new URL(url, window.location.origin).searchParams.get('q') ?? '';
}

export function SearchBox({ className, autoFocus }: { className?: string; autoFocus?: boolean }) {
    const { url } = usePage();
    const onSearchPage = url.startsWith('/search');
    const [value, setValue] = useState(() => (onSearchPage ? currentQuery(url) : ''));
    const inputRef = useRef<HTMLInputElement>(null);

    // Уходим со страницы поиска — поле очищается; приходим — подхватывает запрос.
    useEffect(() => {
        setValue(onSearchPage ? currentQuery(url) : '');
    }, [onSearchPage, url]);

    // «/» — фокус на поиск, как на YouTube.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement;
            if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(target.tagName) && !target.isContentEditable) {
                event.preventDefault();
                inputRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const q = value.trim();
        if (!q) {
            return;
        }
        router.get('/search', { q }, { preserveState: onSearchPage, preserveScroll: false });
        inputRef.current?.blur();
    };

    return (
        <form onSubmit={submit} role="search" className={cn('relative w-full', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
                ref={inputRef}
                type="search"
                value={value}
                autoFocus={autoFocus}
                onChange={(event) => setValue(event.target.value)}
                placeholder="Поиск видео и каналов"
                aria-label="Поиск"
                className="h-10 w-full rounded-full border border-transparent bg-black/[0.05] pr-10 pl-10 text-[15px] outline-none transition placeholder:text-muted-foreground focus:border-ring/60 focus:bg-background focus:ring-4 focus:ring-ring/15 dark:bg-white/[0.07] dark:focus:bg-white/[0.04] [&::-webkit-search-cancel-button]:hidden"
            />
            {value && (
                <button
                    type="button"
                    onClick={() => {
                        setValue('');
                        inputRef.current?.focus();
                    }}
                    className="absolute top-1/2 right-2.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Очистить"
                >
                    <X className="size-3.5" />
                </button>
            )}
        </form>
    );
}
