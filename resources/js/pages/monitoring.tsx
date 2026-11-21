import { Head, router, useForm } from '@inertiajs/react';
import {
    Activity,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    Eye,
    FilterX,
    KeyRound,
    Link2,
    Plus,
    Power,
    Search,
    ShieldCheck,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import credentialRoutes from '@/routes/api-access/credentials';
import endpointRoutes from '@/routes/api-access/endpoints';
import { index as monitoringIndex } from '@/routes/monitoring';

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type LogEntry = {
    id: number;
    request_id: string;
    method: string;
    route_template: string;
    status_code: number;
    error_code: string | null;
    duration_ms: number;
    user_name: string | null;
    user_email: string | null;
    created_at: string;
};

type AuditEntry = {
    id: number;
    action: string;
    outcome: string;
    request_id: string | null;
    user_name: string | null;
    user_email: string | null;
    meta: Record<string, unknown> | null;
    created_at: string;
};

type TemplateItem = {
    value: 'folder_files' | 'quota_summary';
    label: string;
    scope: string;
    description: string;
    needs_folder: boolean;
    sample: Record<string, unknown>;
};

type FilterState = {
    period: string;
    route: string;
    status: string;
    method: string;
    q: string;
    user_id: string;
    per_page: number;
    audit_q: string;
    audit_action: string;
    audit_outcome: string;
    audit_per_page: number;
    log_view: 'api' | 'audit';
};

type Props = {
    initialTab: 'monitoring' | 'credentials' | 'endpoints';
    initialLogView: 'api' | 'audit';
    serverUrl: string;
    filters: FilterState;
    usersList: Array<{ id: number; name: string; email: string }>;
    hasAnyLogs: boolean;
    summary: {
        total_requests: number;
        count_4xx: number;
        count_5xx: number;
        error_rate_5xx: number;
        p95_ms: number;
        updated_at: string;
    };
    series: Array<{ label: string; total: number; errors: number }>;
    availableRoutes: string[];
    availableAuditActions: string[];
    availableAuditOutcomes: string[];
    logs: Paginated<LogEntry>;
    audits: Paginated<AuditEntry>;
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

const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;

function methodBadgeClass(method: string): string {
    switch (method.toUpperCase()) {
        case 'GET':
            return 'bg-sky-500/15 text-sky-700 dark:text-sky-300 border-sky-500/30';
        case 'POST':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
        case 'PUT':
        case 'PATCH':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30';
        case 'DELETE':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300 border-rose-500/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
}

export default function MonitoringPage({
    initialTab,
    initialLogView,
    serverUrl,
    filters,
    usersList,
    hasAnyLogs,
    summary,
    series,
    availableRoutes,
    availableAuditActions,
    availableAuditOutcomes,
    logs,
    audits,
    endpoints,
    credentials,
    regularFolders,
    issuedToken,
    templates,
}: Props) {
    const [tab, setTab] = useState<'monitoring' | 'credentials' | 'endpoints'>(
        issuedToken ? 'credentials' : initialTab,
    );
    const [logView, setLogView] = useState<'api' | 'audit'>(initialLogView);
    const [apiSearch, setApiSearch] = useState(filters.q);
    const [auditSearch, setAuditSearch] = useState(filters.audit_q);
    const [selectedLog, setSelectedLog] = useState<LogEntry | null>(null);
    const [selectedAudit, setSelectedAudit] = useState<AuditEntry | null>(null);
    const [copiedKey, setCopiedKey] = useState<string | null>(null);
    const [pruning, setPruning] = useState(false);

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

    const hasApiFilter =
        filters.route !== '' ||
        filters.status !== '' ||
        filters.method !== '' ||
        filters.q !== '' ||
        filters.user_id !== '' ||
        filters.period !== '24h';

    const hasAuditFilter =
        filters.audit_q !== '' ||
        filters.audit_action !== '' ||
        filters.audit_outcome !== '' ||
        filters.user_id !== '';

    function buildQueryParams(
        next: Partial<FilterState>,
        pageOverrides?: { logs_page?: number; audit_page?: number },
    ): Record<string, string | number> {
        const merged: FilterState = { ...filters, log_view: logView, ...next };
        const params: Record<string, string | number> = {
            period: merged.period,
            log_view: merged.log_view,
        };
        if (merged.route) params.route = merged.route;
        if (merged.status) params.status = merged.status;
        if (merged.method) params.method = merged.method;
        if (merged.q) params.q = merged.q;
        if (merged.user_id) params.user_id = merged.user_id;
        if (merged.per_page !== 10) params.per_page = merged.per_page;
        if (merged.audit_q) params.audit_q = merged.audit_q;
        if (merged.audit_action) params.audit_action = merged.audit_action;
        if (merged.audit_outcome) params.audit_outcome = merged.audit_outcome;
        if (merged.audit_per_page !== 10) {
            params.audit_per_page = merged.audit_per_page;
        }
        if (pageOverrides?.logs_page && pageOverrides.logs_page > 1) {
            params.logs_page = pageOverrides.logs_page;
        }
        if (pageOverrides?.audit_page && pageOverrides.audit_page > 1) {
            params.audit_page = pageOverrides.audit_page;
        }
        return params;
    }

    function applyFilter(
        next: Partial<FilterState>,
        pageOverrides?: { logs_page?: number; audit_page?: number },
    ) {
        router.get(
            monitoringIndex.url(),
            buildQueryParams(next, pageOverrides),
            { preserveState: true, preserveScroll: true },
        );
    }

    function clearApiFilters() {
        setApiSearch('');
        applyFilter({
            period: '24h',
            route: '',
            status: '',
            method: '',
            q: '',
            user_id: '',
            log_view: 'api',
        });
    }

    function clearAuditFilters() {
        setAuditSearch('');
        applyFilter({
            audit_q: '',
            audit_action: '',
            audit_outcome: '',
            user_id: '',
            log_view: 'audit',
        });
    }

    function handleApiSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        applyFilter({ q: apiSearch.trim(), log_view: 'api' });
    }

    function handleAuditSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        applyFilter({ audit_q: auditSearch.trim(), log_view: 'audit' });
    }

    function switchLogView(nextView: 'api' | 'audit') {
        setLogView(nextView);
    }

    function handlePruneLogs() {
        if (
            !window.confirm(
                'Bersihkan seluruh catatan log API yang berusia lebih dari 30 hari?',
            )
        ) {
            return;
        }
        setPruning(true);
        router.post(
            '/monitoring/prune-logs',
            { days: 30 },
            {
                preserveScroll: true,
                onFinish: () => setPruning(false),
            },
        );
    }

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

    const maxSeries = Math.max(1, ...series.map((s) => s.total));

    return (
        <>
            <Head title="API & Monitoring" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">
                            API &amp; Monitoring
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Pantau lalu lintas API global, audit keamanan,
                            kelola token perangkat, dan endpoint integrasi ·{' '}
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                {serverUrl}
                            </code>
                        </p>
                    </div>
                    <div className="gd-glass inline-flex flex-wrap rounded-full p-1">
                        {(
                            [
                                ['monitoring', 'Monitoring & Audit'],
                                [
                                    'credentials',
                                    `Token & Perangkat (${credentials.length})`,
                                ],
                                [
                                    'endpoints',
                                    `Integrasi (${endpoints.length})`,
                                ],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setTab(key)}
                                className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition sm:text-sm ${
                                    tab === key
                                        ? 'bg-primary text-primary-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
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
                                Salin sekarang. Token rahasia hanya ditampilkan
                                satu kali.
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
                        </CardContent>
                    </Card>
                )}

                {tab === 'monitoring' && (
                    <>
                        {/* Compact KPI strip */}
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Card className="rounded-2xl">
                                <CardHeader className="pb-1.5">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground">
                                        Volume Permintaan ({filters.period})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-extrabold tabular-nums">
                                        {summary.total_requests.toLocaleString(
                                            'id-ID',
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card className="rounded-2xl">
                                <CardHeader className="pb-1.5">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground">
                                        Tingkat Galat 5xx
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-extrabold tabular-nums">
                                        {summary.error_rate_5xx}%
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {summary.count_5xx} galat server
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="rounded-2xl">
                                <CardHeader className="pb-1.5">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground">
                                        Respons 4xx
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-extrabold tabular-nums">
                                        {summary.count_4xx.toLocaleString(
                                            'id-ID',
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Auth, kuota, atau validasi
                                    </p>
                                </CardContent>
                            </Card>
                            <Card className="rounded-2xl">
                                <CardHeader className="pb-1.5">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground">
                                        Latensi p95
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-extrabold tabular-nums">
                                        {summary.p95_ms} ms
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Persentil ke-95
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Slim Traffic Chart */}
                        <Card className="rounded-2xl">
                            <CardHeader className="pb-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <CardTitle className="flex items-center gap-2 text-sm font-bold">
                                            <Activity className="size-4 text-primary" />
                                            Grafik Lalu Lintas API Global
                                        </CardTitle>
                                        <CardDescription className="text-xs">
                                            {summary.total_requests} permintaan
                                            pada jendela {filters.period} ·
                                            Diperbarui{' '}
                                            {formatDateTime(summary.updated_at)}
                                        </CardDescription>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-8 rounded-full text-xs"
                                        disabled={pruning}
                                        onClick={handlePruneLogs}
                                    >
                                        <Trash2 className="size-3.5" />
                                        Bersihkan Log &gt;30 Hari
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="flex h-24 items-end gap-2">
                                    {series.map((bucket, idx) => {
                                        const h = Math.max(
                                            6,
                                            Math.round(
                                                (bucket.total / maxSeries) *
                                                    100,
                                            ),
                                        );
                                        return (
                                            <div
                                                key={`${bucket.label}-${idx}`}
                                                className="flex flex-1 flex-col items-center gap-1"
                                            >
                                                <div className="flex h-16 w-full items-end">
                                                    <div
                                                        className={`w-full rounded-t-md transition-all ${
                                                            bucket.errors > 0
                                                                ? 'bg-amber-500/85'
                                                                : 'bg-primary/80'
                                                        }`}
                                                        style={{
                                                            height: `${h}%`,
                                                        }}
                                                        title={`${bucket.label}: ${bucket.total} permintaan (${bucket.errors} galat)`}
                                                    />
                                                </div>
                                                <span className="text-[10px] text-muted-foreground tabular-nums">
                                                    {bucket.label}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Unified Compact Log & Audit Table Card */}
                        <Card className="rounded-2xl">
                            <CardHeader className="gap-3 pb-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="inline-flex rounded-full border bg-muted/40 p-1">
                                        <button
                                            type="button"
                                            onClick={() => switchLogView('api')}
                                            className={`inline-flex items-center gap-1.5 rounded-full px-3.5 py-1 text-xs font-semibold transition ${
                                                logView === 'api'
                                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            <Activity className="size-3.5" />
                                            Log Permintaan API (
                                            {logs.total.toLocaleString('id-ID')}
                                            )
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                switchLogView('audit')
                                            }
                                            className={`inline-flex items-center gap-1.5 rounded-full px-3.5 py-1 text-xs font-semibold transition ${
                                                logView === 'audit'
                                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            <ShieldCheck className="size-3.5" />
                                            Log Audit Keamanan (
                                            {audits.total.toLocaleString(
                                                'id-ID',
                                            )}
                                            )
                                        </button>
                                    </div>

                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>Baris:</span>
                                        {logView === 'api' ? (
                                            <select
                                                aria-label="Baris log API per halaman"
                                                value={String(filters.per_page)}
                                                onChange={(e) =>
                                                    applyFilter({
                                                        per_page: Number(
                                                            e.target.value,
                                                        ),
                                                        log_view: 'api',
                                                    })
                                                }
                                                className="h-8 rounded-full border bg-card px-2.5 text-xs text-foreground"
                                            >
                                                <option value="10">10</option>
                                                <option value="15">15</option>
                                                <option value="25">25</option>
                                                <option value="50">50</option>
                                            </select>
                                        ) : (
                                            <select
                                                aria-label="Baris log audit per halaman"
                                                value={String(
                                                    filters.audit_per_page,
                                                )}
                                                onChange={(e) =>
                                                    applyFilter({
                                                        audit_per_page: Number(
                                                            e.target.value,
                                                        ),
                                                        log_view: 'audit',
                                                    })
                                                }
                                                className="h-8 rounded-full border bg-card px-2.5 text-xs text-foreground"
                                            >
                                                <option value="10">10</option>
                                                <option value="15">15</option>
                                                <option value="25">25</option>
                                            </select>
                                        )}
                                    </div>
                                </div>

                                {/* Inline Filter Toolbar */}
                                {logView === 'api' ? (
                                    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-2.5">
                                        <form
                                            onSubmit={handleApiSearchSubmit}
                                            className="flex min-w-52 flex-1 items-center gap-1.5"
                                        >
                                            <div className="relative flex-1">
                                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                                <Input
                                                    aria-label="Cari log API"
                                                    value={apiSearch}
                                                    onChange={(e) =>
                                                        setApiSearch(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Cari request ID, rute, error, pengguna…"
                                                    className="h-8 bg-card pl-8 text-xs"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="secondary"
                                                className="h-8 px-3 text-xs"
                                            >
                                                Cari
                                            </Button>
                                        </form>

                                        <select
                                            aria-label="Periode waktu"
                                            value={filters.period}
                                            onChange={(e) =>
                                                applyFilter({
                                                    period: e.target.value,
                                                    log_view: 'api',
                                                })
                                            }
                                            className="h-8 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="1h">1 jam</option>
                                            <option value="24h">24 jam</option>
                                            <option value="7d">7 hari</option>
                                            <option value="30d">30 hari</option>
                                        </select>

                                        <select
                                            aria-label="Filter metode HTTP"
                                            value={filters.method}
                                            onChange={(e) =>
                                                applyFilter({
                                                    method: e.target.value,
                                                    log_view: 'api',
                                                })
                                            }
                                            className="h-8 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">
                                                Semua metode
                                            </option>
                                            {HTTP_METHODS.map((m) => (
                                                <option key={m} value={m}>
                                                    {m}
                                                </option>
                                            ))}
                                        </select>

                                        <select
                                            aria-label="Filter status HTTP"
                                            value={filters.status}
                                            onChange={(e) =>
                                                applyFilter({
                                                    status: e.target.value,
                                                    log_view: 'api',
                                                })
                                            }
                                            className="h-8 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">
                                                Semua status
                                            </option>
                                            <option value="2xx">
                                                2xx Berhasil
                                            </option>
                                            <option value="4xx">
                                                4xx Klien
                                            </option>
                                            <option value="5xx">
                                                5xx Server
                                            </option>
                                        </select>

                                        <select
                                            aria-label="Filter rute"
                                            value={filters.route}
                                            onChange={(e) =>
                                                applyFilter({
                                                    route: e.target.value,
                                                    log_view: 'api',
                                                })
                                            }
                                            className="h-8 max-w-44 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">Semua rute</option>
                                            {availableRoutes.map((r) => (
                                                <option key={r} value={r}>
                                                    {r}
                                                </option>
                                            ))}
                                        </select>

                                        <select
                                            aria-label="Filter pengguna"
                                            value={filters.user_id}
                                            onChange={(e) =>
                                                applyFilter({
                                                    user_id: e.target.value,
                                                    log_view: 'api',
                                                })
                                            }
                                            className="h-8 max-w-44 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">
                                                Semua pengguna
                                            </option>
                                            {usersList.map((u) => (
                                                <option
                                                    key={u.id}
                                                    value={String(u.id)}
                                                >
                                                    {u.name}
                                                </option>
                                            ))}
                                        </select>

                                        {hasApiFilter && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 px-2.5 text-xs"
                                                onClick={clearApiFilters}
                                            >
                                                <FilterX className="size-3.5" />
                                                Reset
                                            </Button>
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-2.5">
                                        <form
                                            onSubmit={handleAuditSearchSubmit}
                                            className="flex min-w-52 flex-1 items-center gap-1.5"
                                        >
                                            <div className="relative flex-1">
                                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                                <Input
                                                    aria-label="Cari log audit"
                                                    value={auditSearch}
                                                    onChange={(e) =>
                                                        setAuditSearch(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Cari aksi audit, request ID, pengguna…"
                                                    className="h-8 bg-card pl-8 text-xs"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="secondary"
                                                className="h-8 px-3 text-xs"
                                            >
                                                Cari
                                            </Button>
                                        </form>

                                        <select
                                            aria-label="Filter aksi audit"
                                            value={filters.audit_action}
                                            onChange={(e) =>
                                                applyFilter({
                                                    audit_action:
                                                        e.target.value,
                                                    log_view: 'audit',
                                                })
                                            }
                                            className="h-8 max-w-48 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">Semua aksi</option>
                                            {availableAuditActions.map((a) => (
                                                <option key={a} value={a}>
                                                    {a}
                                                </option>
                                            ))}
                                        </select>

                                        <select
                                            aria-label="Filter hasil audit"
                                            value={filters.audit_outcome}
                                            onChange={(e) =>
                                                applyFilter({
                                                    audit_outcome:
                                                        e.target.value,
                                                    log_view: 'audit',
                                                })
                                            }
                                            className="h-8 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">
                                                Semua hasil
                                            </option>
                                            {availableAuditOutcomes.map((o) => (
                                                <option key={o} value={o}>
                                                    {o}
                                                </option>
                                            ))}
                                        </select>

                                        <select
                                            aria-label="Filter pengguna audit"
                                            value={filters.user_id}
                                            onChange={(e) =>
                                                applyFilter({
                                                    user_id: e.target.value,
                                                    log_view: 'audit',
                                                })
                                            }
                                            className="h-8 max-w-44 rounded-md border bg-card px-2.5 text-xs"
                                        >
                                            <option value="">
                                                Semua pengguna
                                            </option>
                                            {usersList.map((u) => (
                                                <option
                                                    key={u.id}
                                                    value={String(u.id)}
                                                >
                                                    {u.name}
                                                </option>
                                            ))}
                                        </select>

                                        {hasAuditFilter && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 px-2.5 text-xs"
                                                onClick={clearAuditFilters}
                                            >
                                                <FilterX className="size-3.5" />
                                                Reset
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardHeader>

                            <CardContent className="space-y-3">
                                {logView === 'api' ? (
                                    logs.data.length === 0 ? (
                                        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                            {!hasAnyLogs
                                                ? 'Belum ada permintaan API tercatat.'
                                                : 'Tidak ada log API yang cocok dengan filter ini.'}
                                        </div>
                                    ) : (
                                        <>
                                            <div className="max-h-[420px] overflow-auto rounded-xl border">
                                                <table className="w-full text-left text-xs">
                                                    <thead className="sticky top-0 z-10 border-b bg-muted/90 text-muted-foreground backdrop-blur-sm">
                                                        <tr>
                                                            <th className="px-3 py-2 font-semibold whitespace-nowrap">
                                                                Waktu
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold">
                                                                Pengguna
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold">
                                                                Metode
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold">
                                                                Rute Endpoint
                                                            </th>
                                                            <th className="px-3 py-2 font-semibold">
                                                                Status
                                                            </th>
                                                            <th className="hidden px-3 py-2 text-right font-semibold sm:table-cell">
                                                                Latensi
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-semibold">
                                                                Detail
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y tabular-nums">
                                                        {logs.data.map(
                                                            (log) => (
                                                                <tr
                                                                    key={log.id}
                                                                    onClick={() =>
                                                                        setSelectedLog(
                                                                            log,
                                                                        )
                                                                    }
                                                                    className="cursor-pointer transition hover:bg-muted/50"
                                                                >
                                                                    <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                                        {formatDateTime(
                                                                            log.created_at,
                                                                        )}
                                                                    </td>
                                                                    <td
                                                                        className="max-w-36 truncate px-3 py-2 font-medium"
                                                                        title={
                                                                            log.user_email ??
                                                                            undefined
                                                                        }
                                                                    >
                                                                        {log.user_name ??
                                                                            'Tamu'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        <span
                                                                            className={`inline-flex rounded border px-1.5 py-0.5 font-mono text-[10px] font-bold ${methodBadgeClass(log.method)}`}
                                                                        >
                                                                            {
                                                                                log.method
                                                                            }
                                                                        </span>
                                                                    </td>
                                                                    <td
                                                                        className="max-w-72 truncate px-3 py-2 font-mono"
                                                                        title={
                                                                            log.route_template
                                                                        }
                                                                    >
                                                                        {
                                                                            log.route_template
                                                                        }
                                                                    </td>
                                                                    <td className="px-3 py-2 whitespace-nowrap">
                                                                        <div className="flex items-center gap-1.5">
                                                                            <Badge
                                                                                variant={
                                                                                    log.status_code >=
                                                                                    500
                                                                                        ? 'destructive'
                                                                                        : log.status_code >=
                                                                                            400
                                                                                          ? 'outline'
                                                                                          : 'secondary'
                                                                                }
                                                                            >
                                                                                {
                                                                                    log.status_code
                                                                                }
                                                                            </Badge>
                                                                            {log.error_code && (
                                                                                <span className="hidden max-w-28 truncate font-mono text-[10px] text-muted-foreground xl:inline">
                                                                                    {
                                                                                        log.error_code
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </td>
                                                                    <td
                                                                        className={`hidden px-3 py-2 text-right whitespace-nowrap sm:table-cell ${
                                                                            log.duration_ms >=
                                                                            500
                                                                                ? 'font-semibold text-amber-600 dark:text-amber-400'
                                                                                : 'text-muted-foreground'
                                                                        }`}
                                                                    >
                                                                        {
                                                                            log.duration_ms
                                                                        }{' '}
                                                                        ms
                                                                    </td>
                                                                    <td className="px-3 py-2 text-right">
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            className="h-6 px-2 text-xs"
                                                                            onClick={(
                                                                                e,
                                                                            ) => {
                                                                                e.stopPropagation();
                                                                                setSelectedLog(
                                                                                    log,
                                                                                );
                                                                            }}
                                                                        >
                                                                            <Eye className="size-3.5" />
                                                                        </Button>
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>

                                            {/* Pagination Footer */}
                                            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    Menampilkan {logs.from ?? 0}
                                                    –{logs.to ?? 0} dari{' '}
                                                    {logs.total.toLocaleString(
                                                        'id-ID',
                                                    )}{' '}
                                                    log API
                                                </span>
                                                <div className="flex items-center gap-1.5">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 rounded-full px-2.5 text-xs"
                                                        disabled={
                                                            logs.current_page <=
                                                            1
                                                        }
                                                        onClick={() =>
                                                            applyFilter(
                                                                {
                                                                    log_view:
                                                                        'api',
                                                                },
                                                                {
                                                                    logs_page:
                                                                        logs.current_page -
                                                                        1,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <ChevronLeft className="size-3.5" />
                                                        Sebelumnya
                                                    </Button>
                                                    <span className="px-2 tabular-nums">
                                                        Hal {logs.current_page}{' '}
                                                        / {logs.last_page}
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 rounded-full px-2.5 text-xs"
                                                        disabled={
                                                            logs.current_page >=
                                                            logs.last_page
                                                        }
                                                        onClick={() =>
                                                            applyFilter(
                                                                {
                                                                    log_view:
                                                                        'api',
                                                                },
                                                                {
                                                                    logs_page:
                                                                        logs.current_page +
                                                                        1,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Berikutnya
                                                        <ChevronRight className="size-3.5" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </>
                                    )
                                ) : audits.data.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        Tidak ada catatan audit yang cocok
                                        dengan filter ini.
                                    </div>
                                ) : (
                                    <>
                                        <div className="max-h-[420px] overflow-auto rounded-xl border">
                                            <table className="w-full text-left text-xs">
                                                <thead className="sticky top-0 z-10 border-b bg-muted/90 text-muted-foreground backdrop-blur-sm">
                                                    <tr>
                                                        <th className="px-3 py-2 font-semibold whitespace-nowrap">
                                                            Waktu
                                                        </th>
                                                        <th className="px-3 py-2 font-semibold">
                                                            Pengguna
                                                        </th>
                                                        <th className="px-3 py-2 font-semibold">
                                                            Aksi Audit
                                                        </th>
                                                        <th className="px-3 py-2 font-semibold">
                                                            Hasil
                                                        </th>
                                                        <th className="hidden px-3 py-2 font-semibold md:table-cell">
                                                            Request ID
                                                        </th>
                                                        <th className="px-3 py-2 text-right font-semibold">
                                                            Detail
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y tabular-nums">
                                                    {audits.data.map((a) => (
                                                        <tr
                                                            key={a.id}
                                                            onClick={() =>
                                                                setSelectedAudit(
                                                                    a,
                                                                )
                                                            }
                                                            className="cursor-pointer transition hover:bg-muted/50"
                                                        >
                                                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                                {formatDateTime(
                                                                    a.created_at,
                                                                )}
                                                            </td>
                                                            <td
                                                                className="max-w-40 truncate px-3 py-2 font-medium"
                                                                title={
                                                                    a.user_email ??
                                                                    undefined
                                                                }
                                                            >
                                                                {a.user_name ??
                                                                    'Sistem'}
                                                            </td>
                                                            <td className="px-3 py-2 font-mono font-semibold">
                                                                {a.action}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Badge
                                                                    variant={
                                                                        a.outcome ===
                                                                        'success'
                                                                            ? 'secondary'
                                                                            : 'destructive'
                                                                    }
                                                                >
                                                                    {a.outcome}
                                                                </Badge>
                                                            </td>
                                                            <td className="hidden max-w-40 truncate px-3 py-2 font-mono text-muted-foreground md:table-cell">
                                                                {a.request_id ??
                                                                    '—'}
                                                            </td>
                                                            <td className="px-3 py-2 text-right">
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-6 px-2 text-xs"
                                                                    onClick={(
                                                                        e,
                                                                    ) => {
                                                                        e.stopPropagation();
                                                                        setSelectedAudit(
                                                                            a,
                                                                        );
                                                                    }}
                                                                >
                                                                    <Eye className="size-3.5" />
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>

                                        {/* Audit Pagination Footer */}
                                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                Menampilkan {audits.from ?? 0}–
                                                {audits.to ?? 0} dari{' '}
                                                {audits.total.toLocaleString(
                                                    'id-ID',
                                                )}{' '}
                                                peristiwa audit
                                            </span>
                                            <div className="flex items-center gap-1.5">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 rounded-full px-2.5 text-xs"
                                                    disabled={
                                                        audits.current_page <= 1
                                                    }
                                                    onClick={() =>
                                                        applyFilter(
                                                            {
                                                                log_view:
                                                                    'audit',
                                                            },
                                                            {
                                                                audit_page:
                                                                    audits.current_page -
                                                                    1,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <ChevronLeft className="size-3.5" />
                                                    Sebelumnya
                                                </Button>
                                                <span className="px-2 tabular-nums">
                                                    Hal {audits.current_page} /{' '}
                                                    {audits.last_page}
                                                </span>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 rounded-full px-2.5 text-xs"
                                                    disabled={
                                                        audits.current_page >=
                                                        audits.last_page
                                                    }
                                                    onClick={() =>
                                                        applyFilter(
                                                            {
                                                                log_view:
                                                                    'audit',
                                                            },
                                                            {
                                                                audit_page:
                                                                    audits.current_page +
                                                                    1,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Berikutnya
                                                    <ChevronRight className="size-3.5" />
                                                </Button>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}

                {tab === 'credentials' && (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plus className="size-4 text-primary" />
                                    Terbitkan Token Kredensial
                                </CardTitle>
                                <CardDescription>
                                    Pasangkan ponsel atau klien integrasi baca
                                    terbatas
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
                                            placeholder="Ponsel Utama"
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
                                                Endpoint tujuan
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
                                            placeholder="Tanpa kedaluwarsa"
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
                                    Daftar Kredensial Admin
                                </CardTitle>
                                <CardDescription>
                                    Untuk mencabut token milik pengguna lain,
                                    gunakan menu Pengguna &amp; Storage.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {credentials.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        Belum ada kredensial diterbitkan.
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {credentials.map((cred) => (
                                            <div
                                                key={cred.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                                            >
                                                <div className="space-y-0.5">
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
                                                            ? `/${cred.endpoint_slug}`
                                                            : (cred.device_platform ??
                                                              'mobile')}{' '}
                                                        · Terakhir dipakai:{' '}
                                                        {formatDateTime(
                                                            cred.last_used_at,
                                                        )}
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
                )}

                {tab === 'endpoints' && (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Plus className="size-4 text-primary" />
                                    Buat Endpoint Integrasi
                                </CardTitle>
                                <CardDescription>
                                    Terbitkan endpoint baca terbatas untuk
                                    aplikasi/skrip eksternal
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
                                                    {t.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    {selectedTemplate?.needs_folder && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="ep_folder">
                                                Folder sumber
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
                                        <Label htmlFor="ep_slug">Slug</Label>
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
                                        <InputError
                                            message={endpointForm.errors.slug}
                                        />
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
                            </CardHeader>
                            <CardContent>
                                {endpoints.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        Belum ada endpoint integrasi.
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {endpoints.map((ep) => (
                                            <div
                                                key={ep.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                                            >
                                                <div className="space-y-0.5">
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

            <Dialog
                open={selectedLog !== null}
                onOpenChange={(open) => !open && setSelectedLog(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Detail Permintaan API</DialogTitle>
                        <DialogDescription>
                            Token rahasia dan nama berkas privat selalu
                            disamarkan dari log.
                        </DialogDescription>
                    </DialogHeader>
                    {selectedLog && (
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    ID Permintaan
                                </dt>
                                <dd className="font-mono text-xs break-all">
                                    {selectedLog.request_id}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Pengguna
                                </dt>
                                <dd className="text-xs">
                                    {selectedLog.user_name ?? 'Tamu'}{' '}
                                    {selectedLog.user_email
                                        ? `(${selectedLog.user_email})`
                                        : ''}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Metode &amp; Rute
                                </dt>
                                <dd className="font-mono text-xs">
                                    {selectedLog.method}{' '}
                                    {selectedLog.route_template}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Status HTTP
                                </dt>
                                <dd>{selectedLog.status_code}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Kode Galat
                                </dt>
                                <dd className="font-mono text-xs">
                                    {selectedLog.error_code ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Latensi
                                </dt>
                                <dd className="tabular-nums">
                                    {selectedLog.duration_ms} ms
                                </dd>
                            </div>
                        </dl>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={selectedAudit !== null}
                onOpenChange={(open) => !open && setSelectedAudit(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Detail Audit Keamanan</DialogTitle>
                        <DialogDescription>
                            Catatan aksi administratif &amp; keamanan pada
                            server.
                        </DialogDescription>
                    </DialogHeader>
                    {selectedAudit && (
                        <div className="space-y-4">
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Aksi
                                    </dt>
                                    <dd className="font-mono text-xs font-semibold">
                                        {selectedAudit.action}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Hasil
                                    </dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                selectedAudit.outcome ===
                                                'success'
                                                    ? 'secondary'
                                                    : 'destructive'
                                            }
                                        >
                                            {selectedAudit.outcome}
                                        </Badge>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Pengguna
                                    </dt>
                                    <dd className="text-xs">
                                        {selectedAudit.user_name ?? 'Sistem'}{' '}
                                        {selectedAudit.user_email
                                            ? `(${selectedAudit.user_email})`
                                            : ''}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Waktu
                                    </dt>
                                    <dd className="text-xs tabular-nums">
                                        {formatDateTime(
                                            selectedAudit.created_at,
                                        )}
                                    </dd>
                                </div>
                                <div className="col-span-2">
                                    <dt className="text-xs text-muted-foreground">
                                        ID Permintaan
                                    </dt>
                                    <dd className="font-mono text-xs break-all">
                                        {selectedAudit.request_id ?? '—'}
                                    </dd>
                                </div>
                            </dl>
                            {selectedAudit.meta &&
                                Object.keys(selectedAudit.meta).length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">
                                            Metadata
                                        </div>
                                        <pre className="max-h-48 overflow-auto rounded-lg border bg-muted/40 p-2.5 font-mono text-[11px]">
                                            {JSON.stringify(
                                                selectedAudit.meta,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    </div>
                                )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

MonitoringPage.layout = {
    breadcrumbs: [
        {
            title: 'API & Monitoring',
            href: monitoringIndex(),
        },
    ],
};
