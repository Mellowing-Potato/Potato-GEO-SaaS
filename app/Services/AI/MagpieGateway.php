<?php

namespace App\Services\AI;

use App\Models\ModelConnectionProfile;
use App\Services\Outbound\MagpieRequestScope;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

final class MagpieGateway
{
    public function __construct(
        private readonly MagpieTargets $targets,
        private readonly SafeOutboundHttpClient $outbound,
        private readonly SecureHttpFactory $http,
    ) {}

    public function targetRevision(ModelConnectionProfile $profile): string
    {
        return MagpieRequestScope::forProfile($this->targets, $profile, '/api/hello')->target->revision;
    }

    public function directory(ModelConnectionProfile $profile, bool $refresh = false): array
    {
        $catalog = $this->catalog($profile, $refresh);

        return ['version' => $catalog['version'], 'models' => $catalog['models']];
    }

    public function model(ModelConnectionProfile $profile): array
    {
        return $this->selected($profile, $this->catalog($profile, refresh: true));
    }

    public function post(ModelConnectionProfile $profile, array $payload, int $timeout): Response
    {
        $catalog = $this->catalog($profile, refresh: false);
        $model = $this->selected($profile, $catalog);
        if (($payload['model'] ?? null) !== $model['id']) {
            throw new MagpieException('connection_changed');
        }
        $scope = MagpieRequestScope::forProfile($this->targets, $profile, '/v1/chat/completions', $catalog['pinned_ip']);
        $payload['stream'] = false;

        return $this->request($scope, $scope->bearer(), $timeout, $payload);
    }

    private function catalog(ModelConnectionProfile $profile, bool $refresh): array
    {
        $scope = MagpieRequestScope::forProfile($this->targets, $profile, '/api/hello');
        $credential = $scope->bearer();
        $key = 'magpie.catalog.'.hash('sha256', serialize([
            $profile->tenant_id, $profile->id, $profile->revision, $scope->target->revision, $scope->target->baseUrl,
        ]));
        $cached = $refresh ? null : Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        Cache::forget($key);

        try {
            $ip = $this->outbound->resolveTarget($scope->url(), $scope)->selectedIp;
            $helloScope = MagpieRequestScope::forProfile($this->targets, $profile, '/api/hello', $ip);
            $hello = $this->request($helloScope, null, 10);
            if ($hello->status() === 401 || $hello->status() === 403) {
                throw new MagpieException('authentication_failed');
            }
            $version = $hello->json('version');
            if (! $hello->ok() || $hello->json('name') !== 'magpie'
                || ! is_string($version) || preg_match('/\A[A-Za-z0-9._+\-]{1,80}\z/', $version) !== 1) {
                throw new MagpieException('invalid_identity');
            }
            $modelsScope = MagpieRequestScope::forProfile($this->targets, $profile, '/v1/models', $ip);
            $response = $this->request($modelsScope, $credential, 15);
        } catch (OutboundRequestFailedException) {
            throw new MagpieException('unreachable');
        } catch (OutboundRequestBlockedException $e) {
            throw new MagpieException(match ($e->reasonCode) {
                'magpie_target_unauthorized' => 'target_unauthorized',
                'magpie_connection_changed', 'magpie_address_changed' => 'connection_changed',
                'magpie_connection_disabled' => 'connection_disabled',
                'magpie_address_not_authorized' => 'target_unauthorized',
                'dns_resolution_failed' => 'unreachable',
                default => 'invalid_catalog',
            });
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new MagpieException('authentication_failed');
        }

        $rows = $response->json('data');
        if (! $response->ok() || ! is_array($rows) || ! array_is_list($rows)) {
            throw new MagpieException('invalid_catalog');
        }
        $models = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ModelConnectionProfile::validModelId($row['id'] ?? null)) {
                throw new MagpieException('invalid_catalog');
            }
            if (! in_array($row['kind'] ?? 'chat', ['chat', 'model'], true)) {
                continue;
            }
            $id = $row['id'];
            if (isset($seen[$id])) {
                throw new MagpieException('invalid_catalog');
            }
            $seen[$id] = true;
            $input = is_array($row['modalities'] ?? null) ? ($row['modalities']['input'] ?? null) : null;
            $models[] = [
                'id' => $id,
                'display_name' => ModelConnectionProfile::validModelId($row['display_name'] ?? null) ? $row['display_name'] : $id,
                'image_input' => is_array($input) && array_is_list($input) ? in_array('image', $input, true) : null,
                // The gateway catalog does not advertise JSON response format support.
                'json_object' => null,
                'json_schema' => null,
            ];
        }

        if ($models === []) {
            throw new MagpieException('empty_catalog');
        }
        // An edit or authorization change during discovery cannot repopulate this profile's current catalog.
        $scope->currentProfile();
        $catalog = ['version' => $version, 'models' => $models, 'pinned_ip' => $ip];
        Cache::put($key, $catalog, 30);

        return $catalog;
    }

    private function selected(ModelConnectionProfile $profile, array $catalog): array
    {
        if (! ModelConnectionProfile::validModelId($profile->model)) {
            throw new MagpieException('model_required');
        }
        foreach ($catalog['models'] as $model) {
            if ($model['id'] !== $profile->model) {
                continue;
            }
            if ($profile->slot === 'vision' && $model['image_input'] !== true) {
                throw new MagpieException($model['image_input'] === false ? 'image_unsupported' : 'image_unconfirmed');
            }

            return $model;
        }

        throw new MagpieException('model_unavailable');
    }

    private function request(
        MagpieRequestScope $scope,
        #[\SensitiveParameter] ?string $credential,
        int $timeout,
        array $payload = [],
    ): Response {
        $maxBytes = min((int) config('geo.outbound_ai_max_bytes', 8 * 1024 * 1024),
            (int) config('geo.outbound_max_bytes', 50 * 1024 * 1024));
        if ($scope->url() === $scope->target->origin.'/api/hello') {
            $maxBytes = min($maxBytes, 16384);
        }
        $request = $this->http->acceptJson()->asJson()->connectTimeout(5)->timeout($timeout)
            ->withHeaders(['User-Agent' => 'Yanji']);
        if ($credential !== null) {
            $request->withToken($credential);
        }

        return $this->outbound->withEnforcement()->send(
            $request, $scope->method(), $scope->url(), $payload, $maxBytes, magpie: $scope,
        );
    }
}
