import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="gd-aurora relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div className="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r">
                <div
                    className="absolute inset-0"
                    style={{
                        background:
                            'linear-gradient(135deg, #1E1B4B 0%, #312E81 55%, #0E7490 100%)',
                    }}
                />
                <Link
                    href={home()}
                    className="relative z-20 flex items-center gap-3 text-lg font-extrabold tracking-tight"
                >
                    <div
                        className="flex size-9 items-center justify-center rounded-xl text-white ring-1 ring-white/35"
                        style={{
                            background:
                                'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                            boxShadow:
                                '0 8px 20px -4px rgba(79, 70, 229, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.4)',
                        }}
                    >
                        <AppLogoIcon className="size-5" />
                    </div>
                    {name || 'GunyDrive'}
                </Link>
            </div>
            <div className="w-full lg:p-8">
                <div className="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <Link
                        href={home()}
                        className="relative z-20 flex items-center justify-center gap-2.5 lg:hidden"
                    >
                        <div
                            className="flex size-10 items-center justify-center rounded-xl text-white ring-1 ring-white/35"
                            style={{
                                background:
                                    'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                            }}
                        >
                            <AppLogoIcon className="size-5" />
                        </div>
                        <span className="text-base font-extrabold tracking-tight">
                            GunyDrive
                        </span>
                    </Link>
                    <div className="flex flex-col items-start gap-2 text-left sm:items-center sm:text-center">
                        <h1 className="text-xl font-bold">{title}</h1>
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
