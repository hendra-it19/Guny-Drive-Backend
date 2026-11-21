import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    CloudUpload,
    Cpu,
    Database,
    FolderOpen,
    HardDrive,
    Link2,
    Lock,
    RefreshCw,
    Server,
    Smartphone,
    Trash2,
    UsersRound,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatBytes, formatDateTime, formatPercent } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as monitoringIndex } from '@/routes/monitoring';
import { cleanup } from '@/routes/storage';

type Props = {
    storageLinkReady: boolean;
    usage: {
        quota_bytes: number;
        used_bytes: number;
        reserved_bytes: number;
        available_bytes: number;
        private_bytes: number;
        trash_bytes: number;
        total_files: number;
        disk_free_bytes: number | null;
        over_quota: boolean;
        updated_at: string;
    };
    globalStorage: {
        used_bytes: number;
        quota_bytes: number;
        reserved_bytes: number;
        total_files: number;
        total_folders: number;
        private_bytes: number;
        private_count: number;
        trash_bytes: number;
        trash_count: number;
        disk_total_bytes: number | null;
        disk_free_bytes: number | null;
        disk_used_bytes: number | null;
        physical_reserve_bytes: number;
        categories: Array<{
            category: string;
            label: string;
            bytes: number;
            count: number;
        }>;
    };
    userStats: {
        total_users: number;
        admin_users: number;
        verified_users: number;
        over_quota_users: number;
        near_quota_users: number;
    };
    topUsers: Array<{
        id: number;
        name: string;
        email: string;
        username: string | null;
        is_admin: boolean;
        used_bytes: number;
        quota_bytes: number;
        files_count: number;
    }>;
    sessions: Array<{
        id: string;
        user_name: string;
        user_email: string;
        space: string;
        status: string;
        total_size_bytes: number;
        received_offset: number;
        reserved_bytes: number;
        failure_reason: string | null;
        expires_at: string;
        updated_at: string | null;
    }>;
    system: {
        php_version: string;
        laravel_version: string;
        storage_disk: string;
        db_driver: string;
        memory_limit: string;
        upload_max_filesize: string;
    };
    transfers: {
        active: number;
        failed_7d: number;
        last_backup_at: string | null;
    };
    counts: {
        devices: number;
        active_endpoints: number;
        active_credentials: number;
        requests_24h: number;
        errors_4xx_24h: number;
        errors_5xx_24h: number;
    };
    devices: Array<{
        id: string;
        name: string;
        platform: string | null;
        owner_name: string;
        owner_email: string;
        last_seen_at: string | null;
        last_backup_at: string | null;
        revoked: boolean;
    }>;
    vaultConfigured: boolean;
    worker: {
        last_ran_at: string;
        status: string;
        summary: string | null;
    } | null;
};

export default function Dashboard({
    storageLinkReady,
    usage,
    globalStorage,
    userStats,
    topUsers,
    sessions,
    system,
    transfers,
    counts,
    devices,
    worker,
}: Props) {
    const [runningCleanup, setRunningCleanup] = useState(false);
    const [abortingId, setAbortingId] = useState<string | null>(null);

    const globalQuotaPct = formatPercent(
        globalStorage.used_bytes,
        Math.max(1, globalStorage.quota_bytes),
    );
    const diskUsedPct =
        globalStorage.disk_total_bytes && globalStorage.disk_used_bytes !== null
            ? formatPercent(
                  globalStorage.disk_used_bytes,
                  globalStorage.disk_total_bytes,
              )
            : 0;

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

    function handleAbortSession(sessionId: string) {
        setAbortingId(sessionId);
        router.delete(`/storage/sessions/${sessionId}`, {
            preserveScroll: true,
            onFinish: () => setAbortingId(null),
        });
    }

    return (
        <>
            <Head title="Ringkasan & Resource" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">
                            Ringkasan &amp; Resource Server
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Pantauan global penyimpanan, pengguna, resource
                            disk, transfer aktif, dan kesehatan API · Diperbarui{' '}
                            {formatDateTime(usage.updated_at)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="rounded-full"
                            onClick={handleCleanup}
                            disabled={runningCleanup}
                        >
                            <RefreshCw
                                className={`size-4 ${runningCleanup ? 'animate-spin' : ''}`}
                            />
                            Bersihkan Sesi &amp; Sampah
                        </Button>
                        <Button asChild className="rounded-full">
                            <Link href="/users">
                                <UsersRound className="size-4" />
                                Pengguna &amp; Storage
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Actionable Health Alerts */}
                {!storageLinkReady && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        <div className="flex items-start gap-3">
                            <Link2 className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <div>
                                <p className="font-bold">
                                    Storage link publik belum aktif
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Cadangan berkas tetap aman pada disk privat,
                                    namun avatar dan aset publik memerlukan
                                    tautan <code>public/storage</code>.
                                </p>
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="secondary"
                            className="rounded-full"
                            onClick={() => router.post('/storage/link')}
                        >
                            Buat Storage Link
                        </Button>
                    </div>
                )}

                {userStats.over_quota_users > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-destructive/40 bg-destructive/10 p-4 text-sm">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive" />
                            <div>
                                <p className="font-bold text-destructive">
                                    {userStats.over_quota_users} pengguna
                                    melebihi kuota penyimpanan
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Unggahan baru akun tersebut ditahan. Periksa
                                    rincian berkas atau sesuaikan kuota di menu
                                    Pengguna &amp; Storage.
                                </p>
                            </div>
                        </div>
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            className="rounded-full"
                        >
                            <Link href="/users?filter=near_quota">
                                Tinjau Pengguna
                                <ArrowRight className="size-3.5" />
                            </Link>
                        </Button>
                    </div>
                )}

                {/* 4 Top Global KPIs */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card className="rounded-2xl">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-xs font-semibold text-muted-foreground">
                                Storage Global Terpakai
                            </CardTitle>
                            <HardDrive className="size-4 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-extrabold tabular-nums">
                                {formatBytes(globalStorage.used_bytes)}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                {globalStorage.total_files.toLocaleString(
                                    'id-ID',
                                )}{' '}
                                berkas ·{' '}
                                {globalStorage.total_folders.toLocaleString(
                                    'id-ID',
                                )}{' '}
                                folder · {globalQuotaPct}% alokasi
                            </p>
                        </CardContent>
                    </Card>

                    <Link href="/users" className="block">
                        <Card className="h-full rounded-2xl transition hover:border-primary/40">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-xs font-semibold text-muted-foreground">
                                    Pengguna &amp; Kuota
                                </CardTitle>
                                <UsersRound className="size-4 text-primary" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-extrabold tabular-nums">
                                    {userStats.total_users.toLocaleString(
                                        'id-ID',
                                    )}{' '}
                                    akun
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {userStats.verified_users} terverifikasi ·{' '}
                                    {userStats.near_quota_users} kuota &ge;80%
                                </p>
                            </CardContent>
                        </Card>
                    </Link>

                    <Card className="rounded-2xl">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-xs font-semibold text-muted-foreground">
                                Transfer &amp; Backup
                            </CardTitle>
                            <CloudUpload className="size-4 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-extrabold tabular-nums">
                                {transfers.active} aktif
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {transfers.failed_7d} gagal (7h) · Backup:{' '}
                                {formatDateTime(transfers.last_backup_at)}
                            </p>
                        </CardContent>
                    </Card>

                    <Link href={monitoringIndex()} className="block">
                        <Card className="h-full rounded-2xl transition hover:border-primary/40">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-xs font-semibold text-muted-foreground">
                                    Lalu Lintas API (24 jam)
                                </CardTitle>
                                <Activity className="size-4 text-primary" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-extrabold tabular-nums">
                                    {counts.requests_24h.toLocaleString(
                                        'id-ID',
                                    )}
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {counts.errors_5xx_24h} galat 5xx ·{' '}
                                    {counts.devices} perangkat ·{' '}
                                    {counts.active_credentials} token
                                </p>
                            </CardContent>
                        </Card>
                    </Link>
                </div>

                {/* Global Category Breakdown + Physical Server Disk & Environment */}
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="rounded-2xl lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between gap-4">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-lg">
                                    <FolderOpen className="size-5 text-primary" />
                                    Distribusi Data &amp; Storage Global
                                </CardTitle>
                                <CardDescription>
                                    Akumulasi seluruh berkas pengguna di server
                                    (termasuk Folder Privat &amp; Sampah)
                                </CardDescription>
                            </div>
                            <div className="text-right tabular-nums">
                                <div className="text-lg font-extrabold">
                                    {formatBytes(globalStorage.used_bytes)}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Reservasi:{' '}
                                    {formatBytes(globalStorage.reserved_bytes)}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                                {globalStorage.categories.map((cat) => {
                                    const pct = formatPercent(
                                        cat.bytes,
                                        Math.max(globalStorage.used_bytes, 1),
                                    );
                                    return (
                                        <div
                                            key={cat.category}
                                            className="rounded-xl border bg-background/60 p-3"
                                        >
                                            <div className="text-xs font-medium text-muted-foreground">
                                                {cat.label}
                                            </div>
                                            <div className="mt-1 text-base font-extrabold tabular-nums">
                                                {formatBytes(cat.bytes)}
                                            </div>
                                            <div className="mt-0.5 flex items-center justify-between text-[11px] text-muted-foreground tabular-nums">
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

                                <div className="rounded-xl border bg-background/60 p-3">
                                    <div className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                        <Lock className="size-3 text-primary" />
                                        Folder Privat
                                    </div>
                                    <div className="mt-1 text-base font-extrabold tabular-nums">
                                        {formatBytes(
                                            globalStorage.private_bytes,
                                        )}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground tabular-nums">
                                        {globalStorage.private_count.toLocaleString(
                                            'id-ID',
                                        )}{' '}
                                        berkas rahasia
                                    </div>
                                </div>

                                <div className="rounded-xl border bg-background/60 p-3">
                                    <div className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                        <Trash2 className="size-3 text-muted-foreground" />
                                        Sampah Global
                                    </div>
                                    <div className="mt-1 text-base font-extrabold tabular-nums">
                                        {formatBytes(globalStorage.trash_bytes)}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground tabular-nums">
                                        {globalStorage.trash_count.toLocaleString(
                                            'id-ID',
                                        )}{' '}
                                        menunggu hapus
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Physical Disk & Server Resource Panel */}
                    <Card className="rounded-2xl">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Server className="size-4 text-primary" />
                                Resource Disk &amp; Server
                            </CardTitle>
                            <CardDescription>
                                Kapasitas volume fisik &amp; runtime backend
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            {globalStorage.disk_total_bytes !== null &&
                            globalStorage.disk_free_bytes !== null ? (
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-semibold">
                                            Volume Fisik ({diskUsedPct}%)
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {formatBytes(
                                                globalStorage.disk_free_bytes,
                                            )}{' '}
                                            bebas
                                        </span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="gd-meter h-full rounded-full"
                                            style={{
                                                width: `${diskUsedPct}%`,
                                            }}
                                        />
                                    </div>
                                    <div className="flex justify-between text-[11px] text-muted-foreground tabular-nums">
                                        <span>
                                            Terpakai:{' '}
                                            {formatBytes(
                                                globalStorage.disk_used_bytes,
                                            )}
                                        </span>
                                        <span>
                                            Total:{' '}
                                            {formatBytes(
                                                globalStorage.disk_total_bytes,
                                            )}
                                        </span>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Kapasitas volume fisik tidak tersedia untuk
                                    driver penyimpanan ini.
                                </p>
                            )}

                            <div className="grid grid-cols-2 gap-2 rounded-xl bg-muted/50 p-3 text-xs">
                                <div className="flex items-center gap-1.5">
                                    <Cpu className="size-3.5 text-primary" />
                                    <span>PHP {system.php_version}</span>
                                </div>
                                <div className="flex items-center gap-1.5">
                                    <Database className="size-3.5 text-primary" />
                                    <span className="uppercase">
                                        {system.db_driver}
                                    </span>
                                </div>
                                <div className="text-muted-foreground">
                                    Disk: <strong>{system.storage_disk}</strong>
                                </div>
                                <div className="text-muted-foreground">
                                    Mem: <strong>{system.memory_limit}</strong>
                                </div>
                                <div className="col-span-2 flex items-center justify-between border-t pt-2">
                                    <span className="text-muted-foreground">
                                        Storage Link
                                    </span>
                                    <Badge
                                        variant={
                                            storageLinkReady
                                                ? 'secondary'
                                                : 'destructive'
                                        }
                                    >
                                        {storageLinkReady
                                            ? 'Aktif'
                                            : 'Nonaktif'}
                                    </Badge>
                                </div>
                            </div>

                            <div className="rounded-xl border p-3 text-xs">
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold">
                                        Worker Pembersihan
                                    </span>
                                    <Badge variant="secondary">
                                        {worker?.status === 'ok'
                                            ? 'Sehat'
                                            : 'Siaga'}
                                    </Badge>
                                </div>
                                <p className="mt-1 text-muted-foreground">
                                    {worker
                                        ? `${formatDateTime(worker.last_ran_at)} · ${worker.summary ?? ''}`
                                        : 'Belum tercatat. Gunakan tombol "Bersihkan Sesi & Sampah" di atas.'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Top Storage Consumers + Connected Devices */}
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="rounded-2xl lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="text-base">
                                    Top Pengguna Pemakai Storage
                                </CardTitle>
                                <CardDescription>
                                    Urutan akun berdasarkan ruang penyimpanan
                                    terbesar · Klik untuk detail file &amp;
                                    kuota
                                </CardDescription>
                            </div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/users">
                                    Lihat Semua
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {topUsers.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    Belum ada data pengguna.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {topUsers.map((u) => {
                                        const pct = formatPercent(
                                            u.used_bytes,
                                            Math.max(1, u.quota_bytes),
                                        );
                                        return (
                                            <Link
                                                key={u.id}
                                                href={`/users?q=${encodeURIComponent(u.email)}`}
                                                className="block rounded-xl border p-3 transition hover:border-primary/40 hover:bg-muted/30"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                                    <div className="min-w-0">
                                                        <span className="font-bold">
                                                            {u.name}
                                                        </span>
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            {u.email}
                                                        </span>
                                                    </div>
                                                    <div className="text-xs font-bold tabular-nums">
                                                        {formatBytes(
                                                            u.used_bytes,
                                                        )}{' '}
                                                        /{' '}
                                                        {formatBytes(
                                                            u.quota_bytes,
                                                        )}{' '}
                                                        ({pct}%) ·{' '}
                                                        {u.files_count} file
                                                    </div>
                                                </div>
                                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full ${
                                                            pct >= 90
                                                                ? 'bg-destructive'
                                                                : 'gd-meter'
                                                        }`}
                                                        style={{
                                                            width: `${Math.min(100, pct)}%`,
                                                        }}
                                                    />
                                                </div>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="rounded-2xl">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Smartphone className="size-4 text-primary" />
                                    Perangkat Terbaru
                                </CardTitle>
                                <CardDescription>
                                    Ponsel aktif yang terhubung ke server
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {devices.length === 0 ? (
                                <p className="py-6 text-center text-xs text-muted-foreground">
                                    Belum ada perangkat terhubung.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {devices.map((d) => (
                                        <div
                                            key={d.id}
                                            className="flex items-center justify-between gap-2 py-2.5 text-xs"
                                        >
                                            <div className="min-w-0">
                                                <div className="truncate font-bold">
                                                    {d.name}
                                                </div>
                                                <div className="truncate text-muted-foreground">
                                                    {d.owner_name} ·{' '}
                                                    {d.platform ?? 'mobile'}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    Backup:{' '}
                                                    {formatDateTime(
                                                        d.last_backup_at,
                                                    )}
                                                </div>
                                            </div>
                                            <Badge
                                                variant={
                                                    d.revoked
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {d.revoked
                                                    ? 'Dicabut'
                                                    : 'Aktif'}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Global Active & Failed Transfer Sessions Control */}
                <Card className="rounded-2xl">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CheckCircle2 className="size-4 text-primary" />
                                Kontrol Sesi Transfer Global (Semua Pengguna)
                            </CardTitle>
                            <CardDescription>
                                Pantau sesi unggah aktif/gagal dan lepas
                                reservasi kuota yang tersangkut secara instan
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {sessions.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Tidak ada sesi transfer aktif atau tersangkut
                                saat ini.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b text-xs text-muted-foreground">
                                        <tr>
                                            <th className="py-2 pr-3">
                                                Pengguna
                                            </th>
                                            <th className="py-2 pr-3">Ruang</th>
                                            <th className="py-2 pr-3">
                                                Status
                                            </th>
                                            <th className="py-2 pr-3">
                                                Progres
                                            </th>
                                            <th className="py-2 pr-3">
                                                Reservasi
                                            </th>
                                            <th className="py-2 pr-3">
                                                Catatan
                                            </th>
                                            <th className="py-2 text-right">
                                                Aksi
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y tabular-nums">
                                        {sessions.map((s) => (
                                            <tr key={s.id}>
                                                <td className="py-2.5 pr-3">
                                                    <div className="font-semibold">
                                                        {s.user_name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {s.user_email}
                                                    </div>
                                                </td>
                                                <td className="py-2.5 pr-3 text-xs">
                                                    {s.space === 'private'
                                                        ? 'Privat'
                                                        : 'Biasa'}
                                                </td>
                                                <td className="py-2.5 pr-3">
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
                                                <td className="py-2.5 pr-3 text-xs">
                                                    {formatBytes(
                                                        s.received_offset,
                                                    )}{' '}
                                                    /{' '}
                                                    {formatBytes(
                                                        s.total_size_bytes,
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-3 text-xs">
                                                    {formatBytes(
                                                        s.reserved_bytes,
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-3 text-xs text-muted-foreground">
                                                    {s.failure_reason ??
                                                        formatDateTime(
                                                            s.expires_at,
                                                        )}
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    {s.status === 'open' ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                abortingId ===
                                                                s.id
                                                            }
                                                            onClick={() =>
                                                                handleAbortSession(
                                                                    s.id,
                                                                )
                                                            }
                                                        >
                                                            <XCircle className="size-3.5" />
                                                            Batalkan
                                                        </Button>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            —
                                                        </span>
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

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Ringkasan & Resource',
            href: dashboard(),
        },
    ],
};
