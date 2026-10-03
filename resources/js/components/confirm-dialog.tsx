import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

/** Подтверждение необратимого действия: красная кнопка, по умолчанию — «Отмена». */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    children,
    extra,
    confirmLabel,
    processing,
    confirmDisabled = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    children: ReactNode;
    /** Под описанием: поля ввода и т.п. */
    extra?: ReactNode;
    confirmLabel: string;
    processing: boolean;
    confirmDisabled?: boolean;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={(value) => !processing && onOpenChange(value)}>
            <AlertDialogContent className="rounded-2xl">
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-2">{children}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {extra}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing} className="rounded-full">
                        Отмена
                    </AlertDialogCancel>
                    <Button variant="destructive" className="rounded-full" disabled={processing || confirmDisabled} onClick={onConfirm}>
                        {processing && <Loader2 className="animate-spin" />}
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
