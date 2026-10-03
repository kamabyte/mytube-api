import { router, usePage } from '@inertiajs/react';
import { KeyRound, LockOpen } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import type { SharedProps } from '@/types';

/**
 * Удаление с подтверждением и PIN. PIN спрашивается, пока сессия не
 * разблокирована (после верного PIN сервер не спрашивает его 10 минут).
 */
export function DeleteDialog({
    open,
    onOpenChange,
    url,
    title,
    children,
    onDeleted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    title: string;
    children: ReactNode;
    onDeleted?: () => void;
}) {
    const { deletePin } = usePage<SharedProps>().props;
    const [pin, setPin] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const needsPin = deletePin.configured && !deletePin.unlocked;

    useEffect(() => {
        if (!open) {
            setPin('');
            setError(null);
        }
    }, [open]);

    const submit = () =>
        router.delete(url, {
            data: needsPin ? { pin } : {},
            preserveScroll: true,
            // Неверный PIN — редирект обратно на ту же страницу: диалог
            // должен остаться открытым и показать ошибку.
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onDeleted?.(),
            onError: (errors) => {
                setError(errors.pin ?? Object.values(errors)[0] ?? 'Не удалось удалить.');
                setPin('');
                window.setTimeout(() => input.current?.focus(), 0);
            },
        });

    let extra: ReactNode = null;

    if (!deletePin.configured) {
        extra = (
            <div className="rounded-xl border border-border bg-muted/60 p-3 text-sm">
                <p className="font-medium">PIN для удаления не задан</p>
                <p className="mt-1 text-muted-foreground">
                    Задайте его на сервере: <code className="rounded bg-background px-1.5 py-0.5 text-xs">php artisan mytube:delete-pin</code>
                </p>
            </div>
        );
    } else if (needsPin) {
        extra = (
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    if (pin.length >= 4) submit();
                }}
                className="space-y-1.5"
            >
                <label htmlFor="delete-pin" className="flex items-center gap-1.5 text-sm font-medium">
                    <KeyRound className="size-4 text-muted-foreground" />
                    PIN
                </label>
                <input
                    ref={input}
                    id="delete-pin"
                    type="password"
                    inputMode="numeric"
                    autoComplete="off"
                    autoFocus
                    maxLength={8}
                    value={pin}
                    onChange={(event) => {
                        setPin(event.target.value.replace(/\D/g, ''));
                        setError(null);
                    }}
                    aria-invalid={!!error}
                    className="h-11 w-full rounded-xl border border-input bg-transparent px-3.5 text-center text-lg tracking-[0.5em] outline-none focus:border-ring focus:ring-4 focus:ring-ring/15 aria-invalid:border-destructive"
                />
                {error && <p className="text-sm text-destructive">{error}</p>}
            </form>
        );
    } else {
        extra = (
            <div className="space-y-1.5">
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <LockOpen className="size-3.5" />
                    PIN уже введён — удаление разблокировано на несколько минут.
                </p>
                {error && <p className="text-sm text-destructive">{error}</p>}
            </div>
        );
    }

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            confirmLabel="Удалить"
            processing={processing}
            confirmDisabled={!deletePin.configured || (needsPin && pin.length < 4)}
            onConfirm={submit}
            extra={extra}
        >
            {children}
        </ConfirmDialog>
    );
}
