import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="gd-aurora flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="gd-glass flex flex-col gap-8 rounded-3xl p-7 md:p-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2.5 font-medium"
                        >
                            <div
                                className="flex size-12 items-center justify-center rounded-2xl text-white ring-1 ring-white/35"
                                style={{
                                    background:
                                        'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                                    boxShadow:
                                        '0 10px 24px -6px rgba(79, 70, 229, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.4)',
                                }}
                            >
                                <AppLogoIcon className="size-6" />
                            </div>
                            <span className="text-sm font-extrabold tracking-tight text-foreground">
                                GunyDrive
                            </span>
                        </Link>

                        <div className="space-y-1.5 text-center">
                            <h1 className="text-xl font-bold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
