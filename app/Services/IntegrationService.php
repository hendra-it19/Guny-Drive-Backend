<?php

namespace App\Services;

use App\Enums\IntegrationTemplate;
use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Models\ApiCredential;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\IntegrationEndpoint;
use App\Models\User;

class IntegrationService
{
    public function __construct(
        public AuditService $audit,
        public FileCatalogService $catalog,
        public StorageQuotaService $quota,
    ) {}

    public function createEndpoint(
        User $user,
        string $name,
        string $slug,
        IntegrationTemplate $template,
        ?string $folderId = null,
        ?string $requestId = null,
    ): IntegrationEndpoint {
        $cleanSlug = strtolower(trim($slug));

        if ($template === IntegrationTemplate::FolderFiles) {
            if ($folderId === null) {
                throw new ApiErrorException('folder_required', 'Pilih folder biasa untuk templat daftar berkas.', 422);
            }

            $folder = Folder::query()
                ->whereKey($folderId)
                ->where('user_id', $user->id)
                ->where('space', Space::Regular)
                ->whereNull('deleted_at')
                ->first();

            if ($folder === null) {
                throw new ApiErrorException('invalid_source_folder', 'Folder sumber harus berada di ruang biasa dan aktif.', 422);
            }
        } else {
            $folderId = null;
        }

        $endpoint = IntegrationEndpoint::query()->create([
            'user_id' => $user->id,
            'slug' => $cleanSlug,
            'name' => trim($name),
            'template' => $template,
            'folder_id' => $folderId,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->audit->recordEvent($user->id, 'integration.endpoint_created', 'success', [
            'endpoint_id' => $endpoint->id,
            'slug' => $endpoint->slug,
            'template' => $template->value,
        ], $requestId);

        return $endpoint;
    }

    public function setActive(IntegrationEndpoint $endpoint, bool $active, ?string $requestId = null): IntegrationEndpoint
    {
        $endpoint->is_active = $active;
        $endpoint->disabled_at = $active ? null : now();
        $endpoint->save();

        $this->audit->recordEvent($endpoint->user_id, $active ? 'integration.endpoint_enabled' : 'integration.endpoint_disabled', 'success', [
            'endpoint_id' => $endpoint->id,
            'slug' => $endpoint->slug,
        ], $requestId);

        return $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        IntegrationEndpoint $endpoint,
        ApiCredential $credential,
        ?string $cursor = null,
        int $perPage = 40,
    ): array {
        if (! $endpoint->is_active) {
            throw new ApiErrorException('endpoint_disabled', 'Endpoint integrasi sedang dinonaktifkan.', 403);
        }

        if ($credential->integration_endpoint_id !== $endpoint->id) {
            throw new ApiErrorException('endpoint_forbidden', 'Kredensial tidak berlaku untuk endpoint ini.', 403);
        }

        $requiredScope = $endpoint->template->requiredScope();
        if (! $credential->hasScope($requiredScope)) {
            throw new ApiErrorException('insufficient_scope', 'Kredensial tidak memiliki cakupan '.$requiredScope.'.', 403);
        }

        if ($endpoint->template === IntegrationTemplate::QuotaSummary) {
            $summary = $this->quota->usageSummary($endpoint->user);

            return [
                'endpoint' => $endpoint->slug,
                'template' => $endpoint->template->value,
                'data' => [
                    'quota_bytes' => $summary['quota_bytes'],
                    'used_bytes' => $summary['used_bytes'],
                    'reserved_bytes' => $summary['reserved_bytes'],
                    'available_bytes' => $summary['available_bytes'],
                    'updated_at' => $summary['updated_at'],
                ],
            ];
        }

        $folder = Folder::query()
            ->whereKey($endpoint->folder_id)
            ->where('user_id', $endpoint->user_id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($folder === null) {
            throw new ApiErrorException('source_unavailable', 'Folder sumber tidak lagi tersedia di ruang biasa.', 403);
        }

        $paginated = DriveFile::query()
            ->where('user_id', $endpoint->user_id)
            ->where('space', Space::Regular)
            ->where('folder_id', $folder->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->cursorPaginate(min(100, max(1, $perPage)), ['*'], 'cursor', $cursor);

        return [
            'endpoint' => $endpoint->slug,
            'template' => $endpoint->template->value,
            'folder' => [
                'id' => $folder->id,
                'name' => $folder->name,
            ],
            'data' => collect($paginated->items())->map(fn (DriveFile $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'size_bytes' => $f->size_bytes,
                'mime_type' => $f->mime_type,
                'category' => $f->category->value,
                'sha256' => $f->sha256,
                'created_at' => $f->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $paginated->nextCursor()?->encode(),
        ];
    }
}
