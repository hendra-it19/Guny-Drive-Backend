import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    FolderOpen,
    HardDrive,
    KeyRound,
    Lock,
    RefreshCw,
    Search,
    Shield,
    Trash2,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes, formatDateTime, formatPercent } from '@/lib/format';

type Limits = { quota_bytes: number; max_file_bytes: number };

type CategoryStat = {
    category: string;
    label: string;
    bytes: number;
    count: number;
};

type Credential = {
    id: string;
    label: string;
    client_type: 'device' | 'integration';
    last_used_at: string | null;
};

type Account = {
    id: number;
    name: string;
    email: string;
    username: string | null;
    is_admin: boolean;
    email_verified_at: string | null;
    created_at: string | null;
    storage_policy:
        | (Limits & { used_bytes: number; reserved_bytes: number })
        | null;
    storage_detail: {
        actual_bytes: number;
        regular_bytes: number;
        total_files: number;
        total_folders: number;
        private_bytes: number;
        private_count: number;
        trash_bytes: number;
        trash_count: number;
        devices_count: number;
        last_backup_at: string | null;
        categories: CategoryStat[];
    };
    api_credentials: Credential[];
};

type Props = {
    users: {
        data: Account[];
        total: number;
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    search: string;
    filter: string;
    sort: string;
    summary: {
        total_users: number;
        total_used_bytes: number;
        total_quota_bytes: number;
        total_files: number;
        near_quota_count: number;
        active_tokens_count: number;
    };
    defaults: Limits;
};

const QUOTA_PRESETS_GB = [5, 20, 50, 100, 500] as const;

function AccountCard({ user, defaults }: { user: Account; defaults: Limits }) {
    const limits = user.storage_policy ?? defaults;
    const used =
        user.storage_policy?.used_bytes ?? user.storage_detail.actual_bytes;
    const reserved = user.storage_policy?.reserved_bytes ?? 0;
    const pct = formatPercent(used, Math.max(1, limits.quota_bytes));
    const hasDrift =
        user.storage_policy !== null &&
        Math.abs(
            user.storage_policy.used_bytes - user.storage_detail.actual_bytes,
        ) > 1024;

    const form = useForm({
        quota_mb: String(Math.ceil(limits.quota_bytes / 1048576)),
        max_file_mb: String(Math.ceil(limits.max_file_bytes / 1048576)),
    });
    const revoke = useForm({});
    const [target, setTarget] = useState<Credential | 'all' | null>(null);
    const url = `/users/${user.id}`;
    const credentials = user.api_credentials;

    return (
        <Card className="rounded-2xl">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="break-all">
                                {user.name}
                            </CardTitle>
                            <Badge
                                variant={
                                    user.is_admin ? 'default' : 'secondary'
                                }
                            >
                                {user.is_admin ? 'Admin' : 'Pengguna'}
                            </Badge>
                            <Badge variant="outline">
                                {user.email_verified_at
                                    ? 'Terverifikasi'
                                    : 'Belum Verifikasi'}
                            </Badge>
                            {pct >= 90 && (
                                <Badge variant="destructive">
                                    Kuota {pct}%
                                </Badge>
                            )}
                        </div>
                        <CardDescription className="break-all">
                            {user.email}
                            {user.username && ` · @${user.username}`} ·{' '}
                            {user.storage_detail.total_files.toLocaleString(
                                'id-ID',
                            )}{' '}
                            berkas · {user.storage_detail.total_folders} folder
                            · {user.storage_detail.devices_count} perangkat
                            {user.storage_detail.last_backup_at
                                ? ` · Backup terakhir ${formatDateTime(user.storage_detail.last_backup_at)}`
                                : ''}
                        </CardDescription>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" className="rounded-full" asChild>
                            <Link href={`${url}/storage`}>
                                <FolderOpen className="size-3.5" />
                                Detail Storage &amp; File
                            </Link>
                        </Button>
                        {!user.email_verified_at && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="rounded-full"
                                onClick={() =>
                                    router.post(
                                        `${url}/verify-email`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <CheckCircle2 className="size-3.5" />
                                Verifikasi Email
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            className="rounded-full"
                            onClick={() =>
                                router.patch(
                                    `${url}/role`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Shield className="size-3.5" />
                            {user.is_admin ? 'Cabut Admin' : 'Jadikan Admin'}
                        </Button>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="grid gap-6 lg:grid-cols-2">
                {/* Per-User Storage Breakdown & Quota Controls */}
                <section className="min-w-0 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <HardDrive className="size-4 text-primary" />
                            Rincian Storage ({pct}%)
                        </h2>
                        <div className="flex items-center gap-1.5">
                            {hasDrift && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 px-2 text-xs text-amber-600"
                                    onClick={() =>
                                        router.post(
                                            `${url}/storage/recalculate`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshCw className="size-3" />
                                    Sinkronkan
                                </Button>
                            )}
                            {user.storage_detail.trash_count > 0 && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 px-2 text-xs text-destructive"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Kosongkan ${user.storage_detail.trash_count} berkas Sampah milik ${user.name}?`,
                                            )
                                        ) {
                                            router.post(
                                                `${url}/trash/purge`,
                                                {},
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 className="size-3" />
                                    Purge Sampah (
                                    {formatBytes(
                                        user.storage_detail.trash_bytes,
                                    )}
                                    )
                                </Button>
                            )}
                        </div>
                    </div>

                    <p className="text-xs text-muted-foreground tabular-nums">
                        <strong className="text-foreground">
                            {formatBytes(used)}
                        </strong>{' '}
                        terpakai dari {formatBytes(limits.quota_bytes)} ·{' '}
                        {formatBytes(reserved)} reservasi transfer
                    </p>

                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className={`h-full rounded-full ${
                                pct >= 90 ? 'bg-destructive' : 'gd-meter'
                            }`}
                            style={{ width: `${Math.min(100, pct)}%` }}
                        />
                    </div>

                    {/* Mini Category Pills directly visible on card */}
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {user.storage_detail.categories
                            .filter(
                                (c) =>
                                    c.bytes > 0 ||
                                    [
                                        'image',
                                        'video',
                                        'document',
                                        'audio',
                                    ].includes(c.category),
                            )
                            .map((cat) => (
                                <div
                                    key={cat.category}
                                    className="rounded-lg border bg-muted/30 px-2.5 py-1.5 text-[11px]"
                                >
                                    <div className="text-muted-foreground">
                                        {cat.label}
                                    </div>
                                    <div className="font-bold tabular-nums">
                                        {formatBytes(cat.bytes)}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            ({cat.count})
                                        </span>
                                    </div>
                                </div>
                            ))}
                        <div className="rounded-lg border bg-muted/30 px-2.5 py-1.5 text-[11px]">
                            <div className="inline-flex items-center gap-1 text-muted-foreground">
                                <Lock className="size-2.5" /> Privat
                            </div>
                            <div className="font-bold tabular-nums">
                                {formatBytes(user.storage_detail.private_bytes)}{' '}
                                <span className="font-normal text-muted-foreground">
                                    ({user.storage_detail.private_count})
                                </span>
                            </div>
                        </div>
                        <div className="rounded-lg border bg-muted/30 px-2.5 py-1.5 text-[11px]">
                            <div className="inline-flex items-center gap-1 text-muted-foreground">
                                <Trash2 className="size-2.5" /> Sampah
                            </div>
                            <div className="font-bold tabular-nums">
                                {formatBytes(user.storage_detail.trash_bytes)}{' '}
                                <span className="font-normal text-muted-foreground">
                                    ({user.storage_detail.trash_count})
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Quick Quota Presets */}
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] font-medium text-muted-foreground">
                            Preset kuota:
                        </span>
                        {QUOTA_PRESETS_GB.map((gb) => (
                            <button
                                key={gb}
                                type="button"
                                onClick={() =>
                                    form.setData('quota_mb', String(gb * 1024))
                                }
                                className="rounded-full border px-2 py-0.5 text-[11px] font-semibold transition hover:border-primary hover:text-primary"
                            >
                                {gb} GB
                            </button>
                        ))}
                    </div>

                    <form
                        className="grid gap-3 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(url, {
                                preserveScroll: true,
                                onSuccess: () =>
                                    toast.success(
                                        'Batas penyimpanan disimpan.',
                                    ),
                            });
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor={`quota_mb-${user.id}`}>
                                Kuota akun (MiB)
                            </Label>
                            <Input
                                id={`quota_mb-${user.id}`}
                                type="number"
                                required
                                min="1"
                                max={104857600}
                                value={form.data.quota_mb}
                                onChange={(e) =>
                                    form.setData('quota_mb', e.target.value)
                                }
                            />
                            <InputError message={form.errors.quota_mb} />
                        </div>
                        <FileSizeLimit
                            id={`max_file_mb-${user.id}`}
                            value={form.data.max_file_mb}
                            max={1048576}
                            error={form.errors.max_file_mb}
                            onChange={(value) =>
                                form.setData('max_file_mb', value)
                            }
                        />
                        {Number(form.data.quota_mb) * 1048576 <
                            used + reserved && (
                            <p className="text-xs text-destructive sm:col-span-2">
                                Kuota di bawah pemakaian + reservasi saat ini.
                                Unggahan baru akan ditahan.
                            </p>
                        )}
                        <Button
                            size="sm"
                            className="w-fit rounded-full"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Menyimpan…' : 'Simpan Batas'}
                        </Button>
                    </form>
                </section>

                {/* Active Devices & API Credentials */}
                <section className="min-w-0 space-y-3 border-t pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6">
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <KeyRound className="size-4 text-primary" />
                        Perangkat &amp; Token API{' '}
                        <Badge variant="secondary">
                            {credentials.length} aktif
                        </Badge>
                    </h2>
                    {credentials.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            Belum ada perangkat atau integrasi dengan token
                            aktif.
                        </p>
                    ) : (
                        <>
                            <ul className="divide-y">
                                {credentials.map((token) => (
                                    <li
                                        key={token.id}
                                        className="flex items-center justify-between gap-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium break-words">
                                                {token.label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {token.client_type === 'device'
                                                    ? 'Perangkat'
                                                    : 'Integrasi'}{' '}
                                                ·{' '}
                                                {token.last_used_at
                                                    ? `Aktif ${formatDateTime(token.last_used_at)}`
                                                    : 'Belum digunakan'}
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Cabut akses ${token.label}`}
                                            onClick={() => setTarget(token)}
                                        >
                                            Cabut
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <Button
                                variant="destructive"
                                size="sm"
                                className="rounded-full"
                                onClick={() => setTarget('all')}
                            >
                                Cabut Seluruh Token Akun Ini
                            </Button>
                        </>
                    )}
                    <Dialog
                        open={target !== null}
                        onOpenChange={(open) => {
                            if (!open && !revoke.processing) setTarget(null);
                        }}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Cabut akses API?</DialogTitle>
                                <DialogDescription>
                                    {target === 'all'
                                        ? 'Seluruh token perangkat dan integrasi'
                                        : `Token “${target?.label ?? ''}”`}{' '}
                                    milik {user.email} akan dicabut segera.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    disabled={revoke.processing}
                                    onClick={() => setTarget(null)}
                                >
                                    Batal
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={revoke.processing}
                                    onClick={() => {
                                        if (!target) return;
                                        revoke.delete(
                                            `${url}/credentials${target === 'all' ? '' : '/' + target.id}`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    setTarget(null);
                                                    toast.success(
                                                        'Akses API dicabut.',
                                                    );
                                                },
                                            },
                                        );
                                    }}
                                >
                                    Cabut akses
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
            </CardContent>
        </Card>
    );
}

export default function Users({
    users,
    search,
    filter: activeFilter,
    sort: activeSort,
    summary,
    defaults,
}: Props) {
    const searchForm = useForm({
        q: search,
        filter: activeFilter,
        sort: activeSort,
    });

    function applyParams(
        next: Partial<{ q: string; filter: string; sort: string }>,
    ) {
        const merged = { ...searchForm.data, ...next };
        searchForm.setData(merged);
        router.get(
            '/users',
            {
                ...(merged.q ? { q: merged.q } : {}),
                ...(merged.filter && merged.filter !== 'all'
                    ? { filter: merged.filter }
                    : {}),
                ...(merged.sort ? { sort: merged.sort } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Head title="Pengguna & Storage" />
            <header>
                <h1 className="text-3xl font-extrabold tracking-tight">
                    Pengguna &amp; Detail Storage
                </h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    Pantau rincian penyimpanan per pengguna, inspeksi berkas,
                    atur kuota, kosongkan sampah, dan kontrol akses perangkat.
                </p>
            </header>

            {/* Summary KPIs */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card className="rounded-2xl">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            Total Pengguna
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-extrabold tabular-nums">
                            {summary.total_users.toLocaleString('id-ID')}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {summary.active_tokens_count} token perangkat/API
                            aktif
                        </p>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            Total Storage Terpakai
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-extrabold tabular-nums">
                            {formatBytes(summary.total_used_bytes)}
                        </div>
                        <p className="text-xs text-muted-foreground tabular-nums">
                            Alokasi total{' '}
                            {formatBytes(summary.total_quota_bytes)}
                        </p>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            Total Berkas Aktif
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-extrabold tabular-nums">
                            {summary.total_files.toLocaleString('id-ID')}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Seluruh ruang biasa &amp; privat
                        </p>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            Kuota Kritis (&ge;80%)
                        </CardTitle>
                        {summary.near_quota_count > 0 && (
                            <AlertTriangle className="size-4 text-amber-500" />
                        )}
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-extrabold tabular-nums">
                            {summary.near_quota_count} akun
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Perlu tinjauan kuota / pembersihan
                        </p>
                    </CardContent>
                </Card>
            </div>

            {/* Search, Filter & Sort Toolbar */}
            <Card className="rounded-2xl">
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <UsersRound className="size-4 text-primary" />
                        Daftar Akun{' '}
                        <Badge variant="secondary">{users.total}</Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        className="flex flex-wrap items-center gap-2"
                        role="search"
                        onSubmit={(e) => {
                            e.preventDefault();
                            applyParams({});
                        }}
                    >
                        <Input
                            className="w-full sm:max-w-xs"
                            aria-label="Cari nama, email, atau username"
                            placeholder="Cari nama, email, atau username"
                            maxLength={120}
                            value={searchForm.data.q}
                            onChange={(e) =>
                                searchForm.setData('q', e.target.value)
                            }
                        />
                        <select
                            aria-label="Filter akun"
                            value={searchForm.data.filter}
                            onChange={(e) =>
                                applyParams({ filter: e.target.value })
                            }
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="all">Semua Pengguna</option>
                            <option value="near_quota">
                                Kuota &ge;80% / Penuh
                            </option>
                            <option value="admin">Hanya Admin</option>
                            <option value="unverified">
                                Email Belum Verifikasi
                            </option>
                        </select>
                        <select
                            aria-label="Urutkan pengguna"
                            value={searchForm.data.sort}
                            onChange={(e) =>
                                applyParams({ sort: e.target.value })
                            }
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="storage_desc">
                                Storage Terbesar
                            </option>
                            <option value="newest">Pendaftar Terbaru</option>
                            <option value="name_asc">Nama A–Z</option>
                        </select>
                        <Button type="submit" disabled={searchForm.processing}>
                            <Search className="size-4" />
                            Cari
                        </Button>
                        {(search || activeFilter !== 'all') && (
                            <Button asChild variant="ghost">
                                <Link href="/users">Reset</Link>
                            </Button>
                        )}
                    </form>
                </CardContent>
            </Card>

            {users.data.map((user) => (
                <AccountCard key={user.id} user={user} defaults={defaults} />
            ))}

            {!users.data.length && (
                <Card className="rounded-2xl">
                    <CardContent className="py-8 text-center text-sm text-muted-foreground">
                        {search || activeFilter !== 'all'
                            ? 'Tidak ada pengguna yang cocok dengan filter saat ini.'
                            : 'Belum ada pengguna terdaftar.'}
                    </CardContent>
                </Card>
            )}

            {users.last_page > 1 && (
                <nav
                    aria-label="Halaman pengguna"
                    className="flex flex-wrap items-center gap-3 text-sm"
                >
                    <span>
                        Halaman {users.current_page} dari {users.last_page}
                    </span>
                    {users.prev_page_url && (
                        <Button variant="outline" asChild>
                            <Link href={users.prev_page_url}>Sebelumnya</Link>
                        </Button>
                    )}
                    {users.next_page_url && (
                        <Button variant="outline" asChild>
                            <Link href={users.next_page_url}>Berikutnya</Link>
                        </Button>
                    )}
                </nav>
            )}
        </div>
    );
}

Users.layout = {
    breadcrumbs: [{ title: 'Pengguna & Storage', href: '/users' }],
};
