import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    Link2,
    Plus,
    Power,
    Smartphone,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
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
import { formatDateTime } from '@/lib/format';
import { index as apiAccessIndex } from '@/routes/api-access';
import credentialRoutes from '@/routes/api-access/credentials';
import endpointRoutes from '@/routes/api-access/endpoints';

type TemplateItem = {
    value: 'folder_files' | 'quota_summary';
    label: string;
    scope: string;
    description: string;
    needs_folder: boolean;
    sample: Record<string, unknown>;
};

type Props = {
    serverUrl: string;
    endpoints: Array<{
        id: string;
        slug: string;
        name: string;
        template: string;
        template_label: string;
        required_scope: string;
        folder_id: string | null;
        folder_name: string | null;
        is_active: boolean;
        url: string;
        published_at: string;
    }>;
    credentials: Array<{
        id: string;
        client_type: 'device' | 'integration';
        label: string;
        token_prefix: string;
        scopes: string[];
        device_name: string | null;
        device_platform: string | null;
        endpoint_slug: string | null;
        expires_at: string | null;
        last_used_at: string | null;
        status: 'active' | 'revoked' | 'expired';
    }>;
    regularFolders: Array<{ id: string; name: string }>;
    issuedToken: {
        label: string;
        client_type: 'device' | 'integration';
        plain_token: string;
        server_url?: string;
        endpoint_url?: string;
    } | null;
    templates: TemplateItem[];
};

export default function ApiAccessPage({
    serverUrl,
    endpoints,
    credentials,
    regularFolders,
    issuedToken,
    templates,
}: Props) {
    const [tab, setTab] = useState<'credentials' | 'endpoints'>('credentials');
    const [copiedKey, setCopiedKey] = useState<string | null>(null);

    const endpointForm = useForm({
        name: '',
        slug: '',
        template: 'folder_files' as 'folder_files' | 'quota_summary',
        folder_id: regularFolders[0]?.id ?? '',
    });

    const credentialForm = useForm({
        client_type: 'device' as 'device' | 'integration',
        label: '',
        platform: 'android',
        integration_endpoint_id: endpoints.find((e) => e.is_active)?.id ?? '',
        expires_in_days: '',
    });

    const selectedTemplate =
        templates.find((t) => t.value === endpointForm.data.template) ??
        templates[0];

    function copyText(key: string, text: string) {
        void navigator.clipboard.writeText(text);
        setCopiedKey(key);
        window.setTimeout(() => setCopiedKey(null), 1800);
    }

    function submitEndpoint(e: React.FormEvent) {
        e.preventDefault();
        endpointForm.post(endpointRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => endpointForm.reset('name', 'slug'),
        });
    }

    function submitCredential(e: React.FormEvent) {
        e.preventDefault();
        credentialForm.post(credentialRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => credentialForm.reset('label', 'expires_in_days'),
        });
    }

    return (
        <>
            <Head title="Akses API" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">
                            Akses API
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Hubungkan ponsel, terbitkan endpoint baca pribadi,
                            dan kelola kredensial. Alamat server:{' '}
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                {serverUrl}
                            </code>
                        </p>
                    </div>
                    <div className="gd-glass inline-flex rounded-full p-1">
                        <button
                            type="button"
                            onClick={() => setTab('credentials')}
                            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition ${
                                tab === 'credentials'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            Kredensial ({credentials.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setTab('endpoints')}
                            className={`rounded-full px-4 py-1.5 text-sm font-semibold transition ${
                                tab === 'endpoints'
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            Endpoint Integrasi ({endpoints.length})
                        </button>
                    </div>
                </div>

                {issuedToken && (
                    <Card className="rounded-2xl border-primary/40 bg-accent/40">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <KeyRound className="size-4 text-primary" />
                                Token baru untuk “{issuedToken.label}”
                            </CardTitle>
                            <CardDescription>
                                Salin sekarang. Nilai token rahasia hanya
                                ditampilkan satu kali ini saja.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-3">
                                <code className="flex-1 font-mono text-xs break-all">
                                    {issuedToken.plain_token}
                                </code>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        copyText(
                                            'token',
                                            issuedToken.plain_token,
                                        )
                                    }
                                >
                                    {copiedKey === 'token' ? (
                                        <Check className="size-4" />
                                    ) : (
                                        <Copy className="size-4" />
                                    )}
                                    Salin token
                                </Button>
                            </div>
                            {(issuedToken.server_url ||
                                issuedToken.endpoint_url) && (
                                <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                    <span>
                                        {issuedToken.client_type === 'device'
                                            ? 'Alamat server untuk aplikasi mobile:'
                                            : 'URL Endpoint integrasi:'}{' '}
                                        <strong className="font-mono text-foreground">
                                            {issuedToken.server_url ??
                                                issuedToken.endpoint_url}
                                        </strong>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            copyText(
                                                'url',
                                                issuedToken.server_url ??
                                                    issuedToken.endpoint_url ??
                                                    '',
                                            )
                                        }
                                    >
                                        {copiedKey === 'url'
                                            ? 'Tersalin'
                                            : 'Salin alamat'}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {tab === 'credentials' ? (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plus className="size-4 text-primary" />
                                    Terbitkan Kredensial
                                </CardTitle>
                                <CardDescription>
                                    Buat token untuk memasangkan ponsel atau
                                    memanggil endpoint integrasi.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submitCredential}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="client_type">
                                            Jenis klien
                                        </Label>
                                        <select
                                            id="client_type"
                                            value={
                                                credentialForm.data.client_type
                                            }
                                            onChange={(e) =>
                                                credentialForm.setData(
                                                    'client_type',
                                                    e.target.value as
                                                        | 'device'
                                                        | 'integration',
                                                )
                                            }
                                            className="h-9 rounded-md border bg-background px-3 text-sm"
                                        >
                                            <option value="device">
                                                Perangkat ponsel (Android / iOS)
                                            </option>
                                            <option value="integration">
                                                Integrasi pribadi (baca
                                                terbatas)
                                            </option>
                                        </select>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="cred_label">
                                            Label perangkat / integrasi
                                        </Label>
                                        <Input
                                            id="cred_label"
                                            placeholder={
                                                credentialForm.data
                                                    .client_type === 'device'
                                                    ? 'Ponsel Hendra'
                                                    : 'Skrip Laporan'
                                            }
                                            value={credentialForm.data.label}
                                            onChange={(e) =>
                                                credentialForm.setData(
                                                    'label',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                credentialForm.errors.label
                                            }
                                        />
                                    </div>

                                    {credentialForm.data.client_type ===
                                    'device' ? (
                                        <div className="grid gap-2">
                                            <Label htmlFor="platform">
                                                Platform
                                            </Label>
                                            <select
                                                id="platform"
                                                value={
                                                    credentialForm.data.platform
                                                }
                                                onChange={(e) =>
                                                    credentialForm.setData(
                                                        'platform',
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                            >
                                                <option value="android">
                                                    Android
                                                </option>
                                                <option value="ios">iOS</option>
                                                <option value="web">Web</option>
                                            </select>
                                        </div>
                                    ) : (
                                        <div className="grid gap-2">
                                            <Label htmlFor="integration_endpoint_id">
                                                Endpoint integrasi tujuan
                                            </Label>
                                            <select
                                                id="integration_endpoint_id"
                                                value={
                                                    credentialForm.data
                                                        .integration_endpoint_id
                                                }
                                                onChange={(e) =>
                                                    credentialForm.setData(
                                                        'integration_endpoint_id',
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                            >
                                                <option value="">
                                                    -- Pilih endpoint --
                                                </option>
                                                {endpoints.map((ep) => (
                                                    <option
                                                        key={ep.id}
                                                        value={ep.id}
                                                    >
                                                        /{ep.slug} ({ep.name})
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={
                                                    credentialForm.errors
                                                        .integration_endpoint_id
                                                }
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-2">
                                        <Label htmlFor="expires_in_days">
                                            Masa berlaku dalam hari (opsional)
                                        </Label>
                                        <Input
                                            id="expires_in_days"
                                            type="number"
                                            min="1"
                                            placeholder="Kosongkan untuk tanpa kedaluwarsa"
                                            value={
                                                credentialForm.data
                                                    .expires_in_days
                                            }
                                            onChange={(e) =>
                                                credentialForm.setData(
                                                    'expires_in_days',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        className="w-full rounded-full"
                                        disabled={credentialForm.processing}
                                    >
                                        Terbitkan Token
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card className="rounded-2xl lg:col-span-2">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Daftar Kredensial
                                </CardTitle>
                                <CardDescription>
                                    Pencabutan berlaku segera pada permintaan
                                    API berikutnya.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {credentials.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        Belum ada kredensial yang diterbitkan.
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {credentials.map((cred) => (
                                            <div
                                                key={cred.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3.5 text-sm"
                                            >
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-2">
                                                        {cred.client_type ===
                                                        'device' ? (
                                                            <Smartphone className="size-4 text-primary" />
                                                        ) : (
                                                            <Link2 className="size-4 text-primary" />
                                                        )}
                                                        <span className="font-bold">
                                                            {cred.label}
                                                        </span>
                                                        <Badge
                                                            variant={
                                                                cred.status ===
                                                                'active'
                                                                    ? 'secondary'
                                                                    : 'destructive'
                                                            }
                                                        >
                                                            {cred.status ===
                                                            'active'
                                                                ? 'Aktif'
                                                                : cred.status ===
                                                                    'revoked'
                                                                  ? 'Dicabut'
                                                                  : 'Kedaluwarsa'}
                                                        </Badge>
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        <code className="mr-2">
                                                            {cred.token_prefix}…
                                                        </code>
                                                        {cred.endpoint_slug
                                                            ? `Endpoint: /${cred.endpoint_slug}`
                                                            : `Platform: ${cred.device_platform ?? 'mobile'}`}{' '}
                                                        · Cakupan:{' '}
                                                        {cred.scopes.join(', ')}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        Terakhir dipakai:{' '}
                                                        {formatDateTime(
                                                            cred.last_used_at,
                                                        )}
                                                        {cred.expires_at &&
                                                            ` · Kedaluwarsa: ${formatDateTime(cred.expires_at)}`}
                                                    </div>
                                                </div>
                                                {cred.status === 'active' && (
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        className="rounded-full"
                                                        onClick={() =>
                                                            router.delete(
                                                                credentialRoutes.revoke.url(
                                                                    cred.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                        Cabut
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plus className="size-4 text-primary" />
                                    Buat Endpoint Integrasi
                                </CardTitle>
                                <CardDescription>
                                    Pilih templat baca, tentukan sumber folder
                                    biasa, dan tinjau contoh respons.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submitEndpoint}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="ep_template">
                                            Templat operasi
                                        </Label>
                                        <select
                                            id="ep_template"
                                            value={endpointForm.data.template}
                                            onChange={(e) =>
                                                endpointForm.setData(
                                                    'template',
                                                    e.target.value as
                                                        | 'folder_files'
                                                        | 'quota_summary',
                                                )
                                            }
                                            className="h-9 rounded-md border bg-background px-3 text-sm"
                                        >
                                            {templates.map((t) => (
                                                <option
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label} ({t.scope})
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-xs text-muted-foreground">
                                            {selectedTemplate?.description}
                                        </p>
                                    </div>

                                    {selectedTemplate?.needs_folder && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="ep_folder">
                                                Folder sumber (ruang biasa)
                                            </Label>
                                            <select
                                                id="ep_folder"
                                                value={
                                                    endpointForm.data.folder_id
                                                }
                                                onChange={(e) =>
                                                    endpointForm.setData(
                                                        'folder_id',
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                            >
                                                <option value="">
                                                    -- Pilih folder --
                                                </option>
                                                {regularFolders.map((f) => (
                                                    <option
                                                        key={f.id}
                                                        value={f.id}
                                                    >
                                                        {f.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={
                                                    endpointForm.errors
                                                        .folder_id
                                                }
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-2">
                                        <Label htmlFor="ep_name">
                                            Nama endpoint
                                        </Label>
                                        <Input
                                            id="ep_name"
                                            placeholder="Arsip Dokumen"
                                            value={endpointForm.data.name}
                                            onChange={(e) =>
                                                endpointForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={endpointForm.errors.name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="ep_slug">
                                            Alamat pendek (slug)
                                        </Label>
                                        <Input
                                            id="ep_slug"
                                            placeholder="arsip"
                                            value={endpointForm.data.slug}
                                            onChange={(e) =>
                                                endpointForm.setData(
                                                    'slug',
                                                    e.target.value
                                                        .toLowerCase()
                                                        .replace(
                                                            /[^a-z0-9-]/g,
                                                            '',
                                                        ),
                                                )
                                            }
                                        />
                                        <p className="font-mono text-[11px] text-muted-foreground">
                                            GET {serverUrl}/api/v1/integrations/
                                            {endpointForm.data.slug || 'slug'}
                                        </p>
                                        <InputError
                                            message={endpointForm.errors.slug}
                                        />
                                    </div>

                                    <div className="rounded-xl bg-muted/60 p-3">
                                        <div className="mb-1 text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                            Contoh keluaran JSON
                                        </div>
                                        <pre className="max-h-36 overflow-auto font-mono text-[11px] leading-relaxed">
                                            {JSON.stringify(
                                                selectedTemplate?.sample ?? {},
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    </div>

                                    <Button
                                        type="submit"
                                        className="w-full rounded-full"
                                        disabled={endpointForm.processing}
                                    >
                                        Terbitkan Endpoint
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card className="rounded-2xl lg:col-span-2">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Endpoint Diterbitkan
                                </CardTitle>
                                <CardDescription>
                                    Menonaktifkan endpoint langsung menghentikan
                                    akses tanpa menghapus token.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {endpoints.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        Belum ada endpoint integrasi. Buat satu
                                        dari templat di sebelah kiri.
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {endpoints.map((ep) => (
                                            <div
                                                key={ep.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3.5 text-sm"
                                            >
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-bold">
                                                            {ep.name}
                                                        </span>
                                                        <Badge
                                                            variant={
                                                                ep.is_active
                                                                    ? 'secondary'
                                                                    : 'destructive'
                                                            }
                                                        >
                                                            {ep.is_active
                                                                ? 'Aktif'
                                                                : 'Nonaktif'}
                                                        </Badge>
                                                    </div>
                                                    <div className="font-mono text-xs text-muted-foreground">
                                                        GET {ep.url}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {ep.template_label}
                                                        {ep.folder_name &&
                                                            ` · Folder: ${ep.folder_name}`}{' '}
                                                        · Cakupan:{' '}
                                                        {ep.required_scope}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            copyText(
                                                                ep.id,
                                                                ep.url,
                                                            )
                                                        }
                                                    >
                                                        {copiedKey === ep.id ? (
                                                            <Check className="size-3.5" />
                                                        ) : (
                                                            <Copy className="size-3.5" />
                                                        )}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="rounded-full"
                                                        onClick={() =>
                                                            router.patch(
                                                                endpointRoutes.toggle.url(
                                                                    ep.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Power className="size-3.5" />
                                                        {ep.is_active
                                                            ? 'Nonaktifkan'
                                                            : 'Aktifkan'}
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </>
    );
}

ApiAccessPage.layout = {
    breadcrumbs: [
        {
            title: 'Akses API',
            href: apiAccessIndex(),
        },
    ],
};
