<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\StoreSocialLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreSocialLinksController extends Controller
{
    public function __construct(private readonly StoreSocialLinks $links) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit') || $user->can('store.settings.manage')), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->links->configuration($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'links' => ['required', 'array:'.implode(',', array_keys(StoreSocialLinks::LABELS))]];
        foreach (StoreSocialLinks::LABELS as $key => $label) {
            $rules['links.'.$key] = ['present', 'nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && (! is_string($value) || ! $this->links->validUrl($value))) {
                    $fail('Enter a public HTTP or HTTPS profile URL without credentials.');
                }
            }];
        }
        $data = $request->validate($rules);
        $updated = DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $configuration = $this->links->configuration($locked);
            abort_unless($configuration['version'] === (int) $data['version'], 409, 'Social links changed. Reload before saving.');
            $values = array_map(fn ($value) => trim($value ?? ''), $data['links']);
            $locked->update(['social_links_configuration' => array_replace($locked->social_links_configuration ?? [], ['version' => $configuration['version'] + 1, 'enabled' => (bool) $data['enabled'], 'links' => $values])]);

            return $locked;
        });
        $request->attributes->set('store', $updated);

        return $this->index($request);
    }
}
