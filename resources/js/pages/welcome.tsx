import { Head, Link, usePage } from '@inertiajs/react';
import {
    CloudUpload,
    HardDrive,
    KeyRound,
    Lock,
    Smartphone,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="GunyDrive — Penyimpanan Pribadi" />
            <div className="gd-aurora flex min-h-screen flex-col items-center justify-center bg-background p-6 text-foreground">
                <div className="w-full max-w-4xl space-y-8">
                    <header className="gd-glass flex items-center justify-between rounded-full px-6 py-3">
                        <div className="flex items-center gap-3">
                            <span
                                className="flex size-9 items-center justify-center rounded-xl text-white shadow-sm"
                                style={{
                                    background:
                                        'linear-gradient(135deg, #4F46E5, #8B5CF6 55%, #22B8E0)',
                                }}
                            >
                                <AppLogoIcon className="size-5" />
                            </span>
                            <span className="text-lg font-extrabold tracking-tight">
                                GunyDrive
                            </span>
                        </div>
                        <nav className="flex items-center gap-3">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90"
                                >
                                    Buka Dashboard
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90"
                                >
                                    Masuk Pemilik
                                </Link>
                            )}
                        </nav>
                    </header>

                    <main className="gd-glass rounded-3xl p-8 md:p-12">
                        <div className="max-w-2xl space-y-4">
                            <span className="inline-flex items-center gap-2 rounded-full bg-accent px-3.5 py-1 text-xs font-semibold text-accent-foreground">
                                Self-hosted · Kaca Kristal
                            </span>
                            <h1 className="text-3xl font-extrabold tracking-tight md:text-5xl">
                                Satu tempat aman untuk berkas, foto, dan backup
                                ponsel Anda.
                            </h1>
                            <p className="text-base text-muted-foreground">
                                Cadangkan foto dan dokumen lintas Android dan
                                iOS dengan verifikasi SHA-256, kunci berkas
                                sensitif di Folder Privat, serta kelola kuota
                                dan integrasi API dari satu dashboard.
                            </p>
                            <div className="pt-2">
                                <Link
                                    href={auth.user ? dashboard() : login()}
                                    className="inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 text-sm font-bold text-primary-foreground shadow-sm transition hover:opacity-90"
                                >
                                    {auth.user
                                        ? 'Masuk ke Ringkasan'
                                        : 'Masuk ke Server Pribadi'}
                                </Link>
                            </div>
                        </div>

                        <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                {
                                    icon: CloudUpload,
                                    title: 'Unggah Resumable',
                                    desc: 'Transfer terputus berlanjut dari offset terakhir dengan cek SHA-256.',
                                },
                                {
                                    icon: Smartphone,
                                    title: 'Multi-Perangkat',
                                    desc: 'Token terpisah per ponsel dan pemulihan berkas asli lintas perangkat.',
                                },
                                {
                                    icon: Lock,
                                    title: 'Folder Privat',
                                    desc: 'Sesi frasa sandi berumur pendek, kunci otomatis, terpisah dari pencarian.',
                                },
                                {
                                    icon: KeyRound,
                                    title: 'API Pribadi',
                                    desc: 'Terbitkan endpoint baca per folder dengan token terbatas & monitoring.',
                                },
                            ].map((item) => (
                                <div
                                    key={item.title}
                                    className="rounded-2xl border bg-card p-5"
                                >
                                    <div className="mb-3 flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                        <item.icon className="size-5" />
                                    </div>
                                    <h2 className="text-sm font-bold">
                                        {item.title}
                                    </h2>
                                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                        {item.desc}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </main>

                    <footer className="flex items-center justify-between px-2 text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1.5">
                            <HardDrive className="size-3.5" /> Instalasi pemilik
                            tunggal
                        </span>
                        <span>API v1 · Laravel 13 + Expo SDK 58</span>
                    </footer>
                </div>
            </div>
        </>
    );
}
