import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

export default function AuthCardLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    name?: string;
    title?: string;
    description?: string;
}>) {
    return (
        <div className="gd-aurora flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="flex w-full max-w-md flex-col gap-6">
                <Link
                    href={home()}
                    className="flex items-center gap-2.5 self-center font-medium"
                >
                    <div
                        className="flex size-10 items-center justify-center rounded-xl text-white ring-1 ring-white/35"
                        style={{
                            background:
                                'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                            boxShadow:
                                '0 10px 24px -6px rgba(79, 70, 229, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.4)',
                        }}
                    >
                        <AppLogoIcon className="size-5" />
                    </div>
                    <span className="text-base font-extrabold tracking-tight text-foreground">
                        GunyDrive
                    </span>
                </Link>

                <div className="flex flex-col gap-6">
                    <Card className="gd-glass rounded-3xl border-0">
                        <CardHeader className="px-10 pt-8 pb-0 text-center">
                            <CardTitle className="text-xl">{title}</CardTitle>
                            <CardDescription>{description}</CardDescription>
                        </CardHeader>
                        <CardContent className="px-10 py-8">
                            {children}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}
