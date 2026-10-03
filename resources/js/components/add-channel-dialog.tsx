import { useForm } from '@inertiajs/react';
import { ChevronDown, ListVideo, Loader2, Plus, Tv } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Как на сервере (ChannelImporter::looksLikePlaylist): ?list= или голый id плейлиста. */
function looksLikePlaylist(url: string): boolean {
    const value = url.trim();
    if (/[/?&]/.test(value)) {
        try {
            return !!new URL(value.includes('://') ? value : `https://${value}`).searchParams.get('list');
        } catch {
            return false;
        }
    }
    return /^(PL|OL|FL)[\w-]{10,}$/.test(value);
}

/** Раньше YouTube видео не бывает: с этой даты парсер заберёт канал целиком. */
const WHOLE_CHANNEL_FROM = '2005-01-01';

const CHANNEL_FIELDS = ['sync_from', 'playlist_id'] as const;
const PLAYLIST_FIELDS = ['name', 'thumbnail', 'source_channel'] as const;

const inputClass =
    'h-10 w-full rounded-xl border border-input bg-transparent px-3.5 text-sm outline-none focus:border-ring focus:ring-4 focus:ring-ring/15 aria-invalid:border-destructive';

function today(): string {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint: ReactNode; error?: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? <p className="text-sm text-destructive">{error}</p> : <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Toggle({ checked, onChange, title, children }: { checked: boolean; onChange: (value: boolean) => void; title: string; children: ReactNode }) {
    return (
        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-border/70 p-3.5">
            <Checkbox checked={checked} onCheckedChange={(value) => onChange(value === true)} className="mt-0.5" />
            <span className="text-sm">
                <span className="font-medium">{title}</span>
                <span className="block text-muted-foreground">{children}</span>
            </span>
        </label>
    );
}

export function AddChannelDialog() {
    const [open, setOpen] = useState(false);
    const [advanced, setAdvanced] = useState(false);
    const form = useForm({
        url: '',
        parse_latest: true,
        parse_popular: false,
        sync_from: '',
        playlist_id: '',
        name: '',
        thumbnail: '',
        source_channel: '',
    });
    const playlist = looksLikePlaylist(form.data.url);
    const hiddenFields = playlist ? CHANNEL_FIELDS : PLAYLIST_FIELDS;
    const advancedOpen = advanced || [...CHANNEL_FIELDS, ...PLAYLIST_FIELDS].some((field) => form.errors[field]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Поля другого вида источника не отправляем: сервер их всё равно
        // не применит, а ошибка в скрытом поле была бы не видна.
        form.transform((data) => {
            const payload: Record<string, string | boolean> = { ...data };
            hiddenFields.forEach((field) => delete payload[field]);
            return payload as typeof data;
        });
        form.post('/channels', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setAdvanced(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (form.processing) return;
                setOpen(value);
                if (!value) form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button className="rounded-full">
                    <Plus />
                    Добавить канал
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-2xl sm:max-w-lg">
                <form onSubmit={submit} className="contents">
                    <DialogHeader>
                        <DialogTitle>Новый канал или плейлист</DialogTitle>
                        <DialogDescription>
                            Вставьте ссылку с YouTube. Список видео подтянется сразу, а скачивать их воркер будет по очереди.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label htmlFor="channel-url">Ссылка</Label>
                        <div className="relative">
                            <input
                                id="channel-url"
                                autoFocus
                                value={form.data.url}
                                onChange={(event) => form.setData('url', event.target.value)}
                                placeholder="https://www.youtube.com/@handle"
                                aria-invalid={!!form.errors.url}
                                autoComplete="off"
                                spellCheck={false}
                                className="h-11 w-full rounded-xl border border-input bg-transparent pr-28 pl-3.5 text-[15px] outline-none focus:border-ring focus:ring-4 focus:ring-ring/15 aria-invalid:border-destructive"
                            />
                            {form.data.url.trim() && (
                                <span className="absolute top-1/2 right-2 inline-flex -translate-y-1/2 items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground">
                                    {playlist ? <ListVideo className="size-3" /> : <Tv className="size-3" />}
                                    {playlist ? 'Плейлист' : 'Канал'}
                                </span>
                            )}
                        </div>
                        {form.errors.url ? (
                            <p className="text-sm text-destructive">{form.errors.url}</p>
                        ) : (
                            <p className="text-xs text-muted-foreground">youtube.com/@handle, youtube.com/channel/UC…, ссылка на плейлист (…?list=…)</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Toggle checked={form.data.parse_latest} onChange={(value) => form.setData('parse_latest', value)} title="Следить за новыми видео">
                            {playlist ? 'Забирать всё, что добавлено в плейлист.' : 'Забирать свежие загрузки канала.'}
                        </Toggle>
                        <Toggle checked={form.data.parse_popular} onChange={(value) => form.setData('parse_popular', value)} title="Скачивать и популярные видео">
                            Кроме новых — самые просматриваемые ролики {playlist ? 'плейлиста' : 'канала'} за всё время.
                        </Toggle>
                        {form.errors.parse_latest && <p className="text-sm text-destructive">{form.errors.parse_latest}</p>}
                    </div>

                    <div>
                        <button
                            type="button"
                            onClick={() => setAdvanced(!advancedOpen)}
                            aria-expanded={advancedOpen}
                            className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            <ChevronDown className={cn('size-4 transition-transform', advancedOpen && 'rotate-180')} />
                            Дополнительно
                        </button>

                        {advancedOpen && (
                            <div className="mt-3 space-y-4">
                                {playlist ? (
                                    <>
                                        <Field id="playlist-name" label="Название" hint="Пусто — как у плейлиста на YouTube." error={form.errors.name}>
                                            <input
                                                id="playlist-name"
                                                value={form.data.name}
                                                onChange={(event) => form.setData('name', event.target.value)}
                                                aria-invalid={!!form.errors.name}
                                                autoComplete="off"
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field
                                            id="playlist-source"
                                            label="Канал-источник"
                                            hint="Чья аватарка станет обложкой. Пусто — автор первого видео в плейлисте."
                                            error={form.errors.source_channel}
                                        >
                                            <input
                                                id="playlist-source"
                                                value={form.data.source_channel}
                                                onChange={(event) => form.setData('source_channel', event.target.value)}
                                                placeholder="https://www.youtube.com/@handle"
                                                aria-invalid={!!form.errors.source_channel}
                                                autoComplete="off"
                                                spellCheck={false}
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field id="playlist-thumbnail" label="Обложка" hint="Ссылка на картинку — вместо аватарки канала-источника." error={form.errors.thumbnail}>
                                            <input
                                                id="playlist-thumbnail"
                                                type="url"
                                                value={form.data.thumbnail}
                                                onChange={(event) => form.setData('thumbnail', event.target.value)}
                                                placeholder="https://…"
                                                aria-invalid={!!form.errors.thumbnail}
                                                autoComplete="off"
                                                spellCheck={false}
                                                className={inputClass}
                                            />
                                        </Field>
                                    </>
                                ) : (
                                    <>
                                        <Field
                                            id="channel-sync-from"
                                            label="Забрать видео начиная с"
                                            hint="Пусто — только 50 последних видео, дальше новые. Чтобы скачать канал целиком — «Весь канал»."
                                            error={form.errors.sync_from}
                                        >
                                            <div className="flex gap-2">
                                                <input
                                                    id="channel-sync-from"
                                                    type="date"
                                                    max={today()}
                                                    value={form.data.sync_from}
                                                    onChange={(event) => form.setData('sync_from', event.target.value)}
                                                    aria-invalid={!!form.errors.sync_from}
                                                    className={inputClass}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    className="h-10 shrink-0 rounded-xl"
                                                    onClick={() => form.setData('sync_from', WHOLE_CHANNEL_FROM)}
                                                >
                                                    Весь канал
                                                </Button>
                                            </div>
                                        </Field>
                                        <Field
                                            id="channel-playlist-id"
                                            label="Плейлист вместо загрузок"
                                            hint="id плейлиста (PL…), из которого брать видео вместо всех загрузок канала."
                                            error={form.errors.playlist_id}
                                        >
                                            <input
                                                id="channel-playlist-id"
                                                value={form.data.playlist_id}
                                                onChange={(event) => form.setData('playlist_id', event.target.value)}
                                                placeholder="PL…"
                                                aria-invalid={!!form.errors.playlist_id}
                                                autoComplete="off"
                                                spellCheck={false}
                                                className={inputClass}
                                            />
                                        </Field>
                                    </>
                                )}
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="secondary" className="rounded-full" disabled={form.processing} onClick={() => setOpen(false)}>
                            Отмена
                        </Button>
                        <Button type="submit" className="rounded-full" disabled={form.processing || !form.data.url.trim()}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            Добавить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
