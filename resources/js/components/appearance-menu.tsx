import { Link } from '@inertiajs/react';
import { ArrowDownToLine, ChartColumnBig, ChevronLeft, Ellipsis, Monitor, Moon, Palette, Smartphone, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { isStandaloneApp, type InstallPromptEvent } from '@/lib/pwa';

const OPTIONS: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'system', label: 'Как в системе', icon: Monitor },
    { value: 'light', label: 'Светлая', icon: Sun },
    { value: 'dark', label: 'Тёмная', icon: Moon },
];

export function AppearanceMenu() {
    const { appearance, updateAppearance } = useAppearance();
    const [choosingTheme, setChoosingTheme] = useState(false);
    const [prompt, setPrompt] = useState<InstallPromptEvent | null>(null);
    const [showAppleHelp, setShowAppleHelp] = useState(false);
    const [appleBrowser, setAppleBrowser] = useState(false);

    useEffect(() => {
        const standalone = window.matchMedia('(display-mode: standalone)');
        const isAppleMobile = /iPhone|iPad|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const updateMode = () => setAppleBrowser(isAppleMobile && !isStandaloneApp());
        const onPrompt = (event: Event) => {
            event.preventDefault();
            setPrompt(event as InstallPromptEvent);
        };
        const onInstalled = () => setPrompt(null);
        updateMode();
        standalone.addEventListener('change', updateMode);
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);
        return () => {
            standalone.removeEventListener('change', updateMode);
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, []);

    const install = () => {
        if (prompt) {
            setPrompt(null);
            void prompt.prompt().catch(() => {});
        } else {
            setShowAppleHelp(true);
        }
    };

    return (
        <>
            <DropdownMenu onOpenChange={(open) => { if (open) setChoosingTheme(false); }}>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-11 rounded-full max-md:-mr-[13px] md:size-9">
                        <span className="sr-only md:hidden">Меню приложения</span>
                        <span className="sr-only max-md:hidden">Настройки оформления и приложения</span>
                        <Ellipsis className="size-5 md:hidden" />
                        <Sun className="hidden size-[18px] md:block dark:md:hidden" />
                        <Moon className="hidden size-[18px] dark:md:block" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" collisionPadding={8} className="w-56">
                    <div className="md:hidden">
                        {choosingTheme ? (
                            <DropdownMenuItem className="min-h-11" onSelect={(event) => { event.preventDefault(); setChoosingTheme(false); }}>
                                <ChevronLeft /> Назад
                            </DropdownMenuItem>
                        ) : (
                            <>
                                <DropdownMenuItem asChild className="min-h-11"><Link href="/downloads"><ArrowDownToLine /> Очередь сервера</Link></DropdownMenuItem>
                                <DropdownMenuItem asChild className="min-h-11"><Link href="/statistics"><ChartColumnBig /> Статистика</Link></DropdownMenuItem>
                                <DropdownMenuItem className="min-h-11" onSelect={(event) => { event.preventDefault(); setChoosingTheme(true); }}>
                                    <Palette /> Тема оформления
                                </DropdownMenuItem>
                            </>
                        )}
                    </div>
                    <div className={choosingTheme ? undefined : 'max-md:hidden'}>
                        <DropdownMenuLabel>Тема оформления</DropdownMenuLabel>
                        <DropdownMenuRadioGroup value={appearance} onValueChange={(value) => updateAppearance(value as Appearance)}>
                            {OPTIONS.map(({ value, label, icon: Icon }) => (
                                <DropdownMenuRadioItem key={value} value={value} className="max-md:min-h-11">
                                    <Icon className="size-4 text-muted-foreground" />
                                    {label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </div>
                    {(prompt || appleBrowser) && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={install}>
                                <Smartphone className="size-4 text-muted-foreground" />
                                Установить MyTube
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <Dialog open={showAppleHelp} onOpenChange={setShowAppleHelp}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>MyTube на экране «Домой»</DialogTitle>
                        <DialogDescription>
                            В Safari нажмите «Поделиться», выберите «На экран „Домой“» и нажмите «Добавить».
                        </DialogDescription>
                    </DialogHeader>
                </DialogContent>
            </Dialog>
        </>
    );
}
