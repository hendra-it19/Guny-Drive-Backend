import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div
                className="flex aspect-square size-8 items-center justify-center rounded-xl text-white shadow-sm ring-1 ring-white/30"
                style={{
                    background:
                        'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                    boxShadow:
                        '0 6px 16px -4px rgba(79, 70, 229, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.38)',
                }}
            >
                <AppLogoIcon className="size-4.5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-bold tracking-tight">
                    GunyDrive
                </span>
                <span className="truncate text-[11px] text-muted-foreground">
                    Penyimpanan Pribadi
                </span>
            </div>
        </>
    );
}
