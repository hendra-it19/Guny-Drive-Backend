<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\IntegrationTemplate;
use App\Enums\Space;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\ApiRequestLog;
use App\Models\AuditEvent;
use App\Models\Folder;
use App\Models\IntegrationEndpoint;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        return self::renderUnifiedHub($request, 'monitoring');
    }

    public function pruneLogs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $days = (int) ($validated['days'] ?? 30);

        $deleted = ApiRequestLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$deleted} catatan log API lebih lama dari {$days} hari berhasil dibersihkan.",
        ]);

        return back();
    }

    public static function renderUnifiedHub(Request $request, string $defaultTab = 'monitoring'): Response
    {
        /** @var User $user */
        $user = $request->user();

        $period = is_string($request->query('period')) ? $request->query('period') : '24h';
        $routeFilter = is_string($request->query('route')) ? trim($request->query('route')) : '';
        $statusFilter = is_string($request->query('status')) ? trim($request->query('status')) : '';
        $methodFilter = is_string($request->query('method')) ? strtoupper(trim($request->query('method'))) : '';
        if (! in_array($methodFilter, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $methodFilter = '';
        }
        $searchQuery = mb_substr(trim($request->string('q')->toString()), 0, 120);
        $userFilter = is_string($request->query('user_id')) && ctype_digit($request->query('user_id'))
            ? (int) $request->query('user_id')
            : null;

        $perPageRaw = is_string($request->query('per_page')) && ctype_digit($request->query('per_page'))
            ? (int) $request->query('per_page')
            : 10;
        $perPage = in_array($perPageRaw, [10, 15, 25, 50], true) ? $perPageRaw : 10;

        $auditSearch = mb_substr(trim($request->string('audit_q')->toString()), 0, 120);
        $auditAction = is_string($request->query('audit_action')) ? trim($request->query('audit_action')) : '';
        $auditOutcome = is_string($request->query('audit_outcome')) ? trim($request->query('audit_outcome')) : '';
        $auditPerPageRaw = is_string($request->query('audit_per_page')) && ctype_digit($request->query('audit_per_page'))
            ? (int) $request->query('audit_per_page')
            : 10;
        $auditPerPage = in_array($auditPerPageRaw, [10, 15, 25], true) ? $auditPerPageRaw : 10;

        $logViewRaw = is_string($request->query('log_view')) ? $request->query('log_view') : 'api';
        $logView = in_array($logViewRaw, ['api', 'audit'], true) ? $logViewRaw : 'api';

        $requestedTab = is_string($request->query('tab')) ? $request->query('tab') : $defaultTab;
        $initialTab = in_array($requestedTab, ['monitoring', 'credentials', 'endpoints'], true)
            ? $requestedTab
            : $defaultTab;

        $since = match ($period) {
            '1h' => now()->subHour(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        // System-wide admin monitoring across all users
        $totalAnyLogs = ApiRequestLog::query()->count();

        $baseQuery = ApiRequestLog::query()->where('created_at', '>=', $since);

        if ($userFilter !== null) {
            $baseQuery->where('user_id', $userFilter);
        }

        if ($methodFilter !== '') {
            $baseQuery->where('method', $methodFilter);
        }

        if ($routeFilter !== '') {
            $baseQuery->where('route_template', $routeFilter);
        }

        if ($statusFilter === '2xx') {
            $baseQuery->whereBetween('status_code', [200, 299]);
        } elseif ($statusFilter === '4xx') {
            $baseQuery->whereBetween('status_code', [400, 499]);
        } elseif ($statusFilter === '5xx') {
            $baseQuery->where('status_code', '>=', 500);
        } elseif ($statusFilter !== '' && ctype_digit($statusFilter)) {
            $baseQuery->where('status_code', (int) $statusFilter);
        }

        if ($searchQuery !== '') {
            $like = '%'.$searchQuery.'%';
            $baseQuery->where(function ($q) use ($like): void {
                $q->where('request_id', 'like', $like)
                    ->orWhere('route_template', 'like', $like)
                    ->orWhere('error_code', 'like', $like)
                    ->orWhere('method', 'like', $like)
                    ->orWhereHas('user', function ($uq) use ($like): void {
                        $uq->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    });
            });
        }

        $allMatching = (clone $baseQuery)->get(['status_code', 'duration_ms', 'created_at']);
        $totalCount = $allMatching->count();
        $count4xx = $allMatching->filter(static fn (ApiRequestLog $l): bool => $l->status_code >= 400 && $l->status_code < 500)->count();
        $count5xx = $allMatching->filter(static fn (ApiRequestLog $l): bool => $l->status_code >= 500)->count();
        $errorRate5xx = $totalCount > 0 ? round(($count5xx / $totalCount) * 100, 2) : 0.0;

        /** @var list<int> $durations */
        $durations = $allMatching->pluck('duration_ms')->map(static fn ($v): int => (int) $v)->sort()->values()->all();
        $p95Ms = 0;
        if ($durations !== []) {
            $idx = (int) ceil(count($durations) * 0.95) - 1;
            $p95Ms = $durations[max(0, min($idx, count($durations) - 1))];
        }

        $logsPaginator = (clone $baseQuery)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'logs_page')
            ->withQueryString();

        $logs = [
            'data' => collect($logsPaginator->items())
                ->map(fn (ApiRequestLog $l): array => [
                    'id' => $l->id,
                    'request_id' => $l->request_id,
                    'method' => $l->method,
                    'route_template' => $l->route_template,
                    'status_code' => $l->status_code,
                    'error_code' => $l->error_code,
                    'duration_ms' => $l->duration_ms,
                    'user_name' => $l->user?->name,
                    'user_email' => $l->user?->email,
                    'created_at' => $l->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'current_page' => $logsPaginator->currentPage(),
            'last_page' => $logsPaginator->lastPage(),
            'per_page' => $logsPaginator->perPage(),
            'total' => $logsPaginator->total(),
            'from' => $logsPaginator->firstItem(),
            'to' => $logsPaginator->lastItem(),
        ];

        $availableRoutes = ApiRequestLog::query()
            ->distinct()
            ->orderBy('route_template')
            ->pluck('route_template')
            ->map(static fn ($r): string => (string) $r)
            ->values()
            ->all();

        $bucketCount = 12;
        $spanSeconds = max(60, (int) $since->diffInSeconds(now()));
        $stepSeconds = max(1, (int) ceil($spanSeconds / $bucketCount));
        $series = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $start = $since->copy()->addSeconds($i * $stepSeconds);
            $end = $start->copy()->addSeconds($stepSeconds);
            $bucketItems = $allMatching->filter(
                static fn (ApiRequestLog $l): bool => $l->created_at->greaterThanOrEqualTo($start) && $l->created_at->lessThan($end),
            );
            $series[] = [
                'label' => $period === '7d' || $period === '30d' ? $start->format('d/m') : $start->format('H:i'),
                'total' => $bucketItems->count(),
                'errors' => $bucketItems->filter(static fn (ApiRequestLog $l): bool => $l->status_code >= 400)->count(),
            ];
        }

        $auditQuery = AuditEvent::query()
            ->with('user:id,name,email')
            ->when($userFilter !== null, fn ($q) => $q->where('user_id', $userFilter))
            ->when($auditAction !== '', fn ($q) => $q->where('action', $auditAction))
            ->when($auditOutcome !== '', fn ($q) => $q->where('outcome', $auditOutcome))
            ->when($auditSearch !== '', function ($q) use ($auditSearch): void {
                $like = '%'.$auditSearch.'%';
                $q->where(function ($sub) use ($like): void {
                    $sub->where('action', 'like', $like)
                        ->orWhere('outcome', 'like', $like)
                        ->orWhere('request_id', 'like', $like)
                        ->orWhereHas('user', function ($uq) use ($like): void {
                            $uq->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        });
                });
            });

        $auditsPaginator = $auditQuery
            ->orderByDesc('created_at')
            ->paginate($auditPerPage, ['*'], 'audit_page')
            ->withQueryString();

        $audits = [
            'data' => collect($auditsPaginator->items())
                ->map(fn (AuditEvent $a): array => [
                    'id' => $a->id,
                    'action' => $a->action,
                    'outcome' => $a->outcome,
                    'request_id' => $a->request_id,
                    'user_name' => $a->user?->name,
                    'user_email' => $a->user?->email,
                    'meta' => $a->meta,
                    'created_at' => $a->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'current_page' => $auditsPaginator->currentPage(),
            'last_page' => $auditsPaginator->lastPage(),
            'per_page' => $auditsPaginator->perPage(),
            'total' => $auditsPaginator->total(),
            'from' => $auditsPaginator->firstItem(),
            'to' => $auditsPaginator->lastItem(),
        ];

        $availableAuditActions = AuditEvent::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(static fn ($a): string => (string) $a)
            ->values()
            ->all();

        $availableAuditOutcomes = AuditEvent::query()
            ->distinct()
            ->orderBy('outcome')
            ->pluck('outcome')
            ->map(static fn ($o): string => (string) $o)
            ->values()
            ->all();

        // Integrated API Access data (endpoints + credentials + folders)
        $endpoints = IntegrationEndpoint::query()
            ->with('folder')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntegrationEndpoint $e): array => [
                'id' => $e->id,
                'slug' => $e->slug,
                'name' => $e->name,
                'template' => $e->template->value,
                'template_label' => $e->template->label(),
                'required_scope' => $e->template->requiredScope(),
                'folder_id' => $e->folder_id,
                'folder_name' => $e->folder?->name,
                'is_active' => $e->is_active,
                'url' => url('/api/v1/integrations/'.$e->slug),
                'published_at' => $e->published_at->toIso8601String(),
                'disabled_at' => $e->disabled_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $credentials = ApiCredential::query()
            ->with(['device', 'integrationEndpoint'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiCredential $c): array => [
                'id' => $c->id,
                'client_type' => $c->client_type->value,
                'label' => $c->label,
                'token_prefix' => $c->token_prefix,
                'scopes' => $c->scopes,
                'device_name' => $c->device?->name,
                'device_platform' => $c->device?->platform,
                'endpoint_slug' => $c->integrationEndpoint?->slug,
                'expires_at' => $c->expires_at?->toIso8601String(),
                'last_used_at' => $c->last_used_at?->toIso8601String(),
                'revoked_at' => $c->revoked_at?->toIso8601String(),
                'status' => $c->isRevoked() ? 'revoked' : ($c->isExpired() ? 'expired' : 'active'),
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $regularFolders = Folder::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Folder $f): array => [
                'id' => $f->id,
                'name' => $f->name,
            ])
            ->values()
            ->all();

        $usersList = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->values()
            ->all();

        $issuedToken = $request->session()->pull('issued_token');

        return Inertia::render('monitoring', [
            'initialTab' => $initialTab,
            'initialLogView' => $logView,
            'serverUrl' => rtrim(url('/'), '/'),
            'filters' => [
                'period' => $period,
                'route' => $routeFilter,
                'status' => $statusFilter,
                'method' => $methodFilter,
                'q' => $searchQuery,
                'user_id' => $userFilter !== null ? (string) $userFilter : '',
                'per_page' => $perPage,
                'audit_q' => $auditSearch,
                'audit_action' => $auditAction,
                'audit_outcome' => $auditOutcome,
                'audit_per_page' => $auditPerPage,
                'log_view' => $logView,
            ],
            'usersList' => $usersList,
            'hasAnyLogs' => $totalAnyLogs > 0,
            'summary' => [
                'total_requests' => $totalCount,
                'count_4xx' => $count4xx,
                'count_5xx' => $count5xx,
                'error_rate_5xx' => $errorRate5xx,
                'p95_ms' => $p95Ms,
                'updated_at' => now()->toIso8601String(),
            ],
            'series' => $series,
            'availableRoutes' => $availableRoutes,
            'availableAuditActions' => $availableAuditActions,
            'availableAuditOutcomes' => $availableAuditOutcomes,
            'logs' => $logs,
            'audits' => $audits,
            'endpoints' => $endpoints,
            'credentials' => $credentials,
            'regularFolders' => $regularFolders,
            'issuedToken' => is_array($issuedToken) ? $issuedToken : null,
            'templates' => [
                [
                    'value' => IntegrationTemplate::FolderFiles->value,
                    'label' => IntegrationTemplate::FolderFiles->label(),
                    'scope' => IntegrationTemplate::FolderFiles->requiredScope(),
                    'description' => 'Mengembalikan daftar berkas berhalaman hanya dari satu folder ruang biasa.',
                    'needs_folder' => true,
                    'sample' => [
                        'endpoint' => 'arsip',
                        'template' => 'folder_files',
                        'folder' => ['id' => 'uuid-folder', 'name' => 'Dokumen'],
                        'data' => [
                            ['id' => 'uuid-file', 'name' => 'Catatan.pdf', 'size_bytes' => 124800, 'mime_type' => 'application/pdf'],
                        ],
                        'next_cursor' => null,
                    ],
                ],
                [
                    'value' => IntegrationTemplate::QuotaSummary->value,
                    'label' => IntegrationTemplate::QuotaSummary->label(),
                    'scope' => IntegrationTemplate::QuotaSummary->requiredScope(),
                    'description' => 'Mengembalikan ringkasan kuota, ruang terpakai, reservasi, dan sisa penyimpanan.',
                    'needs_folder' => false,
                    'sample' => [
                        'endpoint' => 'kuota',
                        'template' => 'quota_summary',
                        'data' => [
                            'quota_bytes' => 107374182400,
                            'used_bytes' => 45956150067,
                            'reserved_bytes' => 0,
                            'available_bytes' => 61418032333,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
