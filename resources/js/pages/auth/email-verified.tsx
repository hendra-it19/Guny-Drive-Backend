import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Smartphone } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

export default function EmailVerified() {
    return (
        <>
            <Head title="Email Terverifikasi" />

            <div className="flex flex-col items-center gap-5 text-center">
                <div className="flex size-16 items-center justify-center rounded-2xl bg-emerald-500/15 text-emerald-600 ring-1 ring-emerald-500/30 dark:text-emerald-400">
                    <CheckCircle2 className="size-9" />
                </div>

                <div className="space-y-2 rounded-2xl border border-border/60 bg-background/60 p-4 text-sm text-muted-foreground">
                    <p className="font-semibold text-foreground">
                        Verifikasi Berhasil!
                    </p>
                    <p>
                        Alamat email Anda telah dikonfirmasi dan akun GunyDrive
                        Anda kini aktif. Silakan kembali ke aplikasi mobile
                        GunyDrive atau masuk melalui tombol di bawah ini.
                    </p>
                </div>

                <div className="flex w-full flex-col gap-2.5">
                    <Button asChild className="w-full rounded-full font-bold">
                        <a href="gunydrive://auth">
                            <Smartphone className="mr-2 size-4" />
                            Buka Aplikasi GunyDrive
                        </a>
                    </Button>

                    <Button
                        asChild
                        variant="outline"
                        className="w-full rounded-full font-semibold"
                    >
                        <Link href={login()}>Halaman Masuk Web</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

EmailVerified.layout = {
    title: 'Akun Telah Terverifikasi',
    description:
        'Akun GunyDrive Anda sudah aktif dan siap digunakan untuk masuk serta mencadangkan berkas.',
};
