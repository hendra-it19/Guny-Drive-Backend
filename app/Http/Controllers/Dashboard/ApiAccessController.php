<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\ClientType;
use App\Enums\IntegrationTemplate;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\IntegrationEndpoint;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiAccessController extends Controller
{
    public function __construct(
        public IntegrationService $integrations,
        public ApiCredentialService $credentials,
    ) {}

    public function index(Request $request): Response
    {
        return MonitoringController::renderUnifiedHub($request, 'credentials');
    }

    public function storeEndpoint(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:integration_endpoints,slug'],
            'template' => ['required', Rule::in([IntegrationTemplate::FolderFiles->value, IntegrationTemplate::QuotaSummary->value])],
            'folder_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $template = IntegrationTemplate::from((string) $validated['template']);
        $folderId = isset($validated['folder_id']) && is_string($validated['folder_id']) && $validated['folder_id'] !== ''
            ? $validated['folder_id']
            : null;

        if ($template === IntegrationTemplate::FolderFiles && $folderId === null) {
            return back()->withErrors(['folder_id' => 'Pilih folder biasa untuk templat daftar berkas.']);
        }

        $this->integrations->createEndpoint(
            $user,
            (string) $validated['name'],
            (string) $validated['slug'],
            $template,
            $folderId,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Endpoint integrasi berhasil diterbitkan.',
        ]);

        return back();
    }

    public function toggleEndpoint(Request $request, string $endpointId): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = IntegrationEndpoint::query()
            ->whereKey($endpointId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->integrations->setActive($endpoint, ! $endpoint->is_active);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $endpoint->is_active ? 'Endpoint diaktifkan kembali.' : 'Endpoint dinonaktifkan.',
        ]);

        return back();
    }

    public function storeCredential(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_type' => ['required', Rule::in([ClientType::Device->value, ClientType::Integration->value])],
            'label' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'integration_endpoint_id' => ['nullable', 'uuid'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $clientType = ClientType::from((string) $validated['client_type']);
        $expiresAt = isset($validated['expires_in_days'])
            ? Carbon::now()->addDays((int) $validated['expires_in_days'])
            : null;

        if ($clientType === ClientType::Device) {
            $issued = $this->credentials->issueDeviceToken(
                $user,
                (string) $validated['label'],
                isset($validated['platform']) && is_string($validated['platform']) ? $validated['platform'] : null,
                $expiresAt,
            );

            $request->session()->flash('issued_token', [
                'label' => $issued['credential']->label,
                'client_type' => 'device',
                'plain_token' => $issued['plain_token'],
                'server_url' => rtrim(url('/'), '/'),
            ]);
        } else {
            $endpointId = isset($validated['integration_endpoint_id']) && is_string($validated['integration_endpoint_id'])
                ? $validated['integration_endpoint_id']
                : null;

            $endpoint = $endpointId !== null
                ? IntegrationEndpoint::query()->whereKey($endpointId)->where('user_id', $user->id)->first()
                : null;

            if ($endpoint === null) {
                return back()->withErrors(['integration_endpoint_id' => 'Pilih endpoint integrasi yang valid.']);
            }

            $issued = $this->credentials->issueIntegrationToken(
                $user,
                $endpoint,
                (string) $validated['label'],
                [$endpoint->template->requiredScope()],
                $expiresAt,
            );

            $request->session()->flash('issued_token', [
                'label' => $issued['credential']->label,
                'client_type' => 'integration',
                'plain_token' => $issued['plain_token'],
                'endpoint_url' => url('/api/v1/integrations/'.$endpoint->slug),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kredensial baru diterbitkan. Salin token sekarang karena hanya ditampilkan sekali.',
        ]);

        return back();
    }

    public function revokeCredential(Request $request, string $credentialId): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $credential = ApiCredential::query()
            ->whereKey($credentialId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->credentials->revoke($credential);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kredensial telah dicabut dan berlaku segera.',
        ]);

        return back();
    }
}
