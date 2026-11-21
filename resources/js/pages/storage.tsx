import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    HardDrive,
    Lock,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import FileSizeLimit from '@/components/file-size-limit';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes, formatDateTime, formatPercent } from '@/lib/format';
import { cleanup, index as storageIndex } from '@/routes/storage';
import policyRoutes from '@/routes/storage/policy';

type Props = {
    storageLinkReady: boolean;
    usage: {
        quota_bytes: number;
        used_bytes: number;
        reserved_bytes: number;
        available_bytes: number;
        max_file_bytes: number;
        physical_reserve_bytes: number;
        trash_retention_days: number;
        private_bytes: number;
        private_count: number;
        trash_bytes: number;
        trash_count: number;
        total_files: number;
        disk_free_bytes: number | null;
        disk_total_bytes: number | null;
        over_quota: boolean;
        categories: Array<{
            category: string;
            label: string;
            bytes: number;
            count: number;
        }>;
        updated_at: string;
    };
    sessions: Array<{
        id: string;
        space: string;
        status: string;
        total_size_bytes: number;
        received_offset: number;
        reserved_bytes: number;
        failure_reason: string | null;
        expires_at: string;
        updated_at: string | null;
    }>;
    worker: {
        last_ran_at: string;
        status: string;
        summary: string | null;
    } | null;
};

export default function StoragePage({
    usage,
    sessions,
    worker,
    storageLinkReady,
}: Props) {
    const initialQuotaGb =
        Math.round((usage.quota_bytes / (1024 * 1024 * 1024)) * 10) / 10;
    const initialMaxFileMb = Math.ceil(usage.max_file_bytes / (1024 * 1024));

    const form = useForm({
        quota_gb: String(initialQuotaGb),
        max_file_mb: String(initialMaxFileMb),
    });

    const [confirmLower, setConfirmLower] = useState(false);
    const [runningCleanup, setRunningCleanup] = useState(false);

    const parsedQuotaBytes = Number(form.data.quota_gb) * 1024 * 1024 * 1024;
    const willBeUnderUsage =
        Number.isFinite(parsedQuotaBytes) &&
        parsedQuotaBytes < usage.used_bytes;

    function handlePolicySubmit(e: React.FormEvent) {
        e.preventDefault();
        if (willBeUnderUsage && !confirmLower) {
            setConfirmLower(true);
            return;
        }
        form.put(policyRoutes.update.url(), {
            preserveScroll: true,
            onSuccess: () => setConfirmLower(false),
        });
    }

    function handleCleanup() {
        setRunningCleanup(true);
        router.post(
            cleanup.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRunningCleanup(false),
            },
        );
    }

    const usedPercent = formatPercent(usage.used_bytes, usage.quota_bytes);

    return (
        <>
            <Head title="Penyimpanan" />
            <section className="m-4 rounded-xl border p-4">
                <a href="/users" className="underline">
                    Kelola pengguna dan kuota
                </a>
                <p role="status">
                    {storageLinkReady
                        ? 'Storage link aktif.'
                        : 'Storage link publik belum siap. Cadangan pribadi tetap disimpan pada disk privat.'}
                </p>
                {!storageLinkReady && (
                    <Button onClick={() => router.post('/storage/link')}>
                        Buat storage link
                    </Button>
                )}
            </section>
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">
                            Penyimpanan
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Penggunaan per kategori, batas kuota, reservasi
                            transfer, dan ruang disk fisik.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        className="rounded-full"
                        onClick={handleCleanup}
                        disabled={runningCleanup}
                    >
                        <RefreshCw
                            className={`size-4 ${runningCleanup ? 'animate-spin' : ''}`}
                        />
                        Bersihkan sesi &amp; Sampah kedaluwarsa
                    </Button>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="rounded-2xl lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <HardDrive className="size-5 text-primary" />
                                Rincian Penggunaan
                            </CardTitle>
                            <CardDescription>
                                Terpakai {formatBytes(usage.used_bytes)} dari{' '}
                                {formatBytes(usage.quota_bytes)} ({usedPercent}
                                %) · Dipesan:{' '}
                                {formatBytes(usage.reserved_bytes)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="h-3 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className="gd-meter h-full rounded-full"
                                    style={{ width: `${usedPercent}%` }}
                                />
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                {usage.categories.map((cat) => {
                                    const pct = formatPercent(
                                        cat.bytes,
                                        Math.max(usage.used_bytes, 1),
                                    );
                                    return (
                                        <div
                                            key={cat.category}
                                            className="rounded-xl border bg-background/60 p-3.5"
                                        >
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="font-semibold">
                                                    {cat.label}
                                                </span>
                                                <span className="font-bold tabular-nums">
                                                    {formatBytes(cat.bytes)}
                                                </span>
                                            </div>
                                            <div className="mt-1 flex items-center justify-between text-xs text-muted-foreground">
                                                <span>
                                                    {cat.count.toLocaleString(
                                                        'id-ID',
                                                    )}{' '}
                                                    berkas
                                                </span>
                                                <span>{pct}%</span>
                                            </div>
                                        </div>
                                    );
                                })}

                                <div className="rounded-xl border bg-background/60 p-3.5">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="inline-flex items-center gap-1.5 font-semibold">
                                            <Lock className="size-3.5 text-primary" />
                                            Folder Privat
                                        </span>
                                        <span className="font-bold tabular-nums">
                                            {formatBytes(usage.private_bytes)}
                                        </span>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {usage.private_count} berkas (ikut
                                        dihitung pada total kuota)
                                    </div>
                                </div>

                                <div className="rounded-xl border bg-background/60 p-3.5">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="inline-flex items-center gap-1.5 font-semibold">
                                            <Trash2 className="size-3.5 text-muted-foreground" />
                                            Sampah ({usage.trash_retention_days}{' '}
                                            hari)
                                        </span>
                                        <span className="font-bold tabular-nums">
                                            {formatBytes(usage.trash_bytes)}
                                        </span>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {usage.trash_count} berkas (tetap
                                        dihitung hingga dibersihkan)
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-xl bg-muted/60 p-4 text-xs text-muted-foreground">
                                <h3 className="text-sm font-semibold text-foreground">
                                    Volume penyimpanan backup
                                </h3>
                                <p className="mt-1">
                                    Pemakaian seluruh volume, termasuk data di
                                    luar GunyDrive.
                                </p>
                                {usage.disk_free_bytes === null &&
                                usage.disk_total_bytes === null ? (
                                    <p className="mt-3">
                                        Kapasitas volume tidak tersedia untuk
                                        penyimpanan ini.
                                    </p>
                                ) : (
                                    <dl className="my-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        {(
                                            [
                                                [
                                                    'Terpakai',
                                                    usage.disk_total_bytes !==
                                                        null &&
                                                    usage.disk_free_bytes !==
                                                        null
                                                        ? Math.max(
                                                              0,
                                                              usage.disk_total_bytes -
                                                                  usage.disk_free_bytes,
                                                          )
                                                        : null,
                                                ],
                                                [
                                                    'Kosong',
                                                    usage.disk_free_bytes,
                                                ],
                                                [
                                                    'Total',
                                                    usage.disk_total_bytes,
                                                ],
                                            ] as const
                                        ).map(([label, bytes]) => (
                                            <div key={label}>
                                                <dt>{label}</dt>
                                                <dd className="text-base font-semibold text-foreground tabular-nums">
                                                    {formatBytes(bytes)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                <p className="mt-2">
                                    Cadangan aman disk:{' '}
                                    {formatBytes(usage.physical_reserve_bytes)}
                                </p>
                                {worker && (
                                    <div className="mt-1.5">
                                        Pembersihan terakhir:{' '}
                                        {formatDateTime(worker.last_ran_at)} (
                                        {worker.summary})
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="rounded-2xl">
                        <CardHeader>
                            <CardTitle className="text-lg">
                                Ubah Kuota &amp; Batas Berkas
                            </CardTitle>
                            <CardDescription>
                                Berlaku segera untuk reservasi unggahan
                                berikutnya.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handlePolicySubmit}
                                className="space-y-4"
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="quota_gb">
                                        Kuota penyimpanan (GB)
                                    </Label>
                                    <Input
                                        id="quota_gb"
                                        type="number"
                                        step="0.1"
                                        min="0.1"
                                        value={form.data.quota_gb}
                                        onChange={(e) => {
                                            setConfirmLower(false);
                                            form.setData(
                                                'quota_gb',
                                                e.target.value,
                                            );
                                        }}
                                    />
                                    <InputError
                                        message={form.errors.quota_gb}
                                    />
                                </div>

                                <FileSizeLimit
                                    id="max_file_mb"
                                    value={form.data.max_file_mb}
                                    max={102400}
                                    error={form.errors.max_file_mb}
                                    onChange={(value) =>
                                        form.setData('max_file_mb', value)
                                    }
                                />

                                {willBeUnderUsage && (
                                    <div className="flex items-start gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs">
                                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <div>
                                            <strong>
                                                Kuota lebih rendah dari
                                                pemakaian (
                                                {formatBytes(usage.used_bytes)}
                                                ).
                                            </strong>{' '}
                                            Unggahan baru akan ditolak sampai
                                            ruang tersedia, tetapi berkas yang
                                            sudah tersimpan tetap bisa diunduh.
                                            {confirmLower && (
                                                <span className="mt-1 block font-semibold">
                                                    Klik tombol simpan sekali
                                                    lagi untuk mengonfirmasi.
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <Button
                                    type="submit"
                                    className="w-full rounded-full"
                                    disabled={form.processing}
                                >
                                    {confirmLower
                                        ? 'Konfirmasi & Simpan Kuota'
                                        : 'Simpan Kebijakan'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">
                            Sesi Transfer Aktif &amp; Tertunda
                        </CardTitle>
                        <CardDescription>
                            Nama berkas privat tidak ditampilkan. Sesi
                            kedaluwarsa dilepas oleh pekerjaan pembersihan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {sessions.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Tidak ada sesi transfer aktif atau gagal saat
                                ini.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b text-xs text-muted-foreground">
                                        <tr>
                                            <th className="py-2 pr-4">
                                                ID Sesi
                                            </th>
                                            <th className="py-2 pr-4">Ruang</th>
                                            <th className="py-2 pr-4">
                                                Status
                                            </th>
                                            <th className="py-2 pr-4">
                                                Progres
                                            </th>
                                            <th className="py-2 pr-4">
                                                Reservasi
                                            </th>
                                            <th className="py-2">
                                                Alasan / Kedaluwarsa
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y tabular-nums">
                                        {sessions.map((s) => (
                                            <tr key={s.id}>
                                                <td className="py-2.5 pr-4 font-mono text-xs">
                                                    {s.id.slice(0, 8)}
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    {s.space === 'private'
                                                        ? 'Privat'
                                                        : 'Biasa'}
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    <Badge
                                                        variant={
                                                            s.status === 'open'
                                                                ? 'secondary'
                                                                : 'destructive'
                                                        }
                                                    >
                                                        {s.status}
                                                    </Badge>
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    {formatBytes(
                                                        s.received_offset,
                                                    )}{' '}
                                                    /{' '}
                                                    {formatBytes(
                                                        s.total_size_bytes,
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-4">
                                                    {formatBytes(
                                                        s.reserved_bytes,
                                                    )}
                                                </td>
                                                <td className="py-2.5 text-xs text-muted-foreground">
                                                    {s.failure_reason ??
                                                        formatDateTime(
                                                            s.expires_at,
                                                        )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

StoragePage.layout = {
    breadcrumbs: [
        {
            title: 'Penyimpanan',
            href: storageIndex(),
        },
    ],
};
