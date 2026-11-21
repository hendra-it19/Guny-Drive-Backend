import { Head } from '@inertiajs/react';
import { Check, Sparkles } from 'lucide-react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useAppearance, type DashboardTheme } from '@/hooks/use-appearance';
import { edit as editAppearance } from '@/routes/appearance';

const THEME_OPTIONS: Array<{
    id: DashboardTheme;
    badge: string;
    name: string;
    description: string;
    previewBg: string;
    previewAccent: string;
}> = [
    {
        id: 'kaca',
        badge: 'A',
        name: 'Kaca Kristal',
        description:
            'Glassmorphism modern di atas aurora lembut, panel solid untuk tabel dan angka.',
        previewBg:
            'linear-gradient(135deg, #8EA2FF 0%, #C3A6FF 50%, #7FD8F5 100%)',
        previewAccent: '#4F46E5',
    },
    {
        id: 'minimal',
        badge: 'B',
        name: 'Minimal',
        description:
            'Netral, datar tanpa bayangan, dan padat informasi untuk memantau log API.',
        previewBg: '#F6F7F9',
        previewAccent: '#2563EB',
    },
];

export default function Appearance() {
    const { dashboardTheme, updateDashboardTheme } = useAppearance();

    return (
        <>
            <Head title="Tampilan" />

            <h1 className="sr-only">Pengaturan tampilan</h1>

            <div className="space-y-8">
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Tema Dashboard"
                        description="Memakai sistem token yang sama dengan aplikasi mobile. Pilihan berlaku di peramban ini."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        {THEME_OPTIONS.map((opt) => {
                            const active = dashboardTheme === opt.id;
                            return (
                                <button
                                    key={opt.id}
                                    type="button"
                                    onClick={() => updateDashboardTheme(opt.id)}
                                    aria-pressed={active}
                                    className={`flex items-start gap-3 rounded-2xl border p-4 text-left transition ${
                                        active
                                            ? 'border-primary bg-accent/40 ring-2 ring-primary/30'
                                            : 'bg-card hover:border-primary/40'
                                    }`}
                                >
                                    <span
                                        className="relative mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl border shadow-xs"
                                        style={{ background: opt.previewBg }}
                                    >
                                        <span
                                            className="size-4 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    opt.previewAccent,
                                            }}
                                        />
                                    </span>
                                    <span className="flex-1">
                                        <span className="flex items-center gap-1.5 text-sm font-bold">
                                            {opt.badge} · {opt.name}
                                            {opt.id === 'kaca' && (
                                                <Sparkles className="size-3.5 text-primary" />
                                            )}
                                        </span>
                                        <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                            {opt.description}
                                        </span>
                                    </span>
                                    {active && (
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                            <Check className="size-3.5" />
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Mode Warna"
                        description="Pilih Sistem untuk mengikuti pengaturan perangkat, atau kunci ke Terang / Gelap."
                    />
                    <AppearanceTabs />
                </div>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Tampilan',
            href: editAppearance(),
        },
    ],
};
