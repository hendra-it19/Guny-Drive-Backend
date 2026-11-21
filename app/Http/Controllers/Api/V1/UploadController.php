<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\FileCatalogService;
use App\Services\UploadService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    public function __construct(
        public UploadService $uploads,
        public FileCatalogService $catalog,
        public VaultService $vault,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'filename' => ['required', 'string', 'max:255'],
            'total_size_bytes' => ['required', 'integer', 'min:0'],
            'expected_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'space' => ['sometimes', 'string', 'in:regular,private'],
            'folder_id' => ['nullable', 'uuid'],
            'mime_type' => ['nullable', 'string', 'max:191'],
            'media_taken_at' => ['nullable', 'date'],
            'source_asset_id' => ['nullable', 'string', 'max:255'],
            'source_revision' => ['nullable', 'string', 'max:128'],
            'auto_backup' => ['sometimes', 'boolean'],
            'deduplicate' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');

        $space = Space::from((string) ($validated['space'] ?? 'regular'));
        if ($space === Space::Private) {
            $vaultToken = $request->header('X-Vault-Token');
            $this->vault->validateSession($credential, is_string($vaultToken) ? $vaultToken : null, true);
        }

        $start = fn (): array => $this->uploads->createOrResumeSession(
            user: $user,
            credential: $credential,
            idempotencyKey: (string) $validated['idempotency_key'],
            filename: (string) $validated['filename'],
            totalSizeBytes: (int) $validated['total_size_bytes'],
            expectedSha256: (string) $validated['expected_sha256'],
            space: $space,
            folderId: isset($validated['folder_id']) && is_string($validated['folder_id']) ? $validated['folder_id'] : null,
            mimeType: isset($validated['mime_type']) && is_string($validated['mime_type']) ? $validated['mime_type'] : null,
            mediaTakenAt: isset($validated['media_taken_at']) && is_string($validated['media_taken_at'])
                ? Carbon::parse($validated['media_taken_at'])
                : null,
            sourceAssetId: isset($validated['source_asset_id']) && is_string($validated['source_asset_id']) ? $validated['source_asset_id'] : null,
            sourceRevision: isset($validated['source_revision']) && is_string($validated['source_revision']) ? $validated['source_revision'] : null,
            autoBackup: (bool) ($validated['auto_backup'] ?? false),
            deduplicate: (bool) ($validated['deduplicate'] ?? false),
        );

        $result = ($validated['deduplicate'] ?? false) ? DB::transaction(function () use ($credential, $start): array {
            ApiCredential::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();

            return $start();
        }) : $start();

        if (isset($result['existing_file']) && ! isset($result['session'])) {
            return response()->json(['file' => $this->catalog->serializeFile($result['existing_file'])])
                ->header('Cache-Control', 'no-store, private');
        }

        if (($result['skipped'] ?? false) === true || ! isset($result['session'])) {
            return response()->json([
                'skipped' => true,
                'reason' => $result['reason'] ?? 'handled',
            ], 200);
        }

        $session = $result['session'];
        $payload = ['upload' => $this->serializeSession($session)];
        if (isset($result['existing_file'])) {
            $payload['file'] = $this->catalog->serializeFile($result['existing_file']);
        }

        $response = response()->json($payload, isset($result['existing_file']) ? 200 : 201);
        if ($space === Space::Private) {
            $response->header('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function show(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->resolveOwnedSession($request, $uploadId);

        return response()->json([
            'upload' => $this->serializeSession($session),
        ]);
    }

    public function patch(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->resolveOwnedSession($request, $uploadId);

        $offsetHeader = $request->header('Upload-Offset');
        if (! is_string($offsetHeader) || ! ctype_digit($offsetHeader)) {
            throw new ApiErrorException('missing_upload_offset', 'Header Upload-Offset (bilangan bulat) diperlukan.', 400);
        }

        $chunkSha256 = $request->header('Upload-Checksum');
        $updated = $this->uploads->appendChunk(
            $session,
            (int) $offsetHeader,
            $request->getContent(),
            is_string($chunkSha256) ? $chunkSha256 : null,
        );

        return response()->json([
            'upload' => $this->serializeSession($updated),
        ]);
    }

    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->resolveOwnedSession($request, $uploadId);
        $wasCompleted = $session->completed_file_id !== null;
        $file = $this->uploads->completeSession($session);

        $response = response()->json([
            'file' => $this->catalog->serializeFile($file),
        ], $wasCompleted ? 200 : 201);

        if ($session->space === Space::Private) {
            $response->header('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function destroy(Request $request, string $uploadId): JsonResponse
    {
        $session = $this->resolveOwnedSession($request, $uploadId);
        $this->uploads->abortSession($session);

        return response()->json(['aborted' => true]);
    }

    private function resolveOwnedSession(Request $request, string $uploadId): UploadSession
    {
        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');

        $session = UploadSession::query()
            ->whereKey($uploadId)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            throw new ApiErrorException('upload_not_found', 'Sesi unggah tidak ditemukan.', 404);
        }

        if ($session->space === Space::Private) {
            $vaultToken = $request->header('X-Vault-Token');
            $this->vault->validateSession($credential, is_string($vaultToken) ? $vaultToken : null, true);
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(UploadSession $session): array
    {
        return [
            'id' => $session->id,
            'space' => $session->space->value,
            'folder_id' => $session->folder_id,
            'filename' => $session->filename,
            'total_size_bytes' => $session->total_size_bytes,
            'received_offset' => $session->received_offset,
            'status' => $session->status->value,
            'completed_file_id' => $session->completed_file_id,
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }
}
