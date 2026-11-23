import { Monitor, Moon, Smartphone, Sun } from 'lucide-react';
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
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-11 rounded-full max-md:-mr-[13px] md:size-9" aria-label="Настройки оформления и приложения">
                        <Sun className="size-[18px] dark:hidden" />
                        <Moon className="hidden size-[18px] dark:block" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel>Тема</DropdownMenuLabel>
                    <DropdownMenuRadioGroup value={appearance} onValueChange={(value) => updateAppearance(value as Appearance)}>
                        {OPTIONS.map(({ value, label, icon: Icon }) => (
                            <DropdownMenuRadioItem key={value} value={value}>
                                <Icon className="size-4 text-muted-foreground" />
                                {label}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>
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
