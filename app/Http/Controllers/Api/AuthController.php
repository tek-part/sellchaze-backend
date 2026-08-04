<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ConnectGoogleRequest;
use App\Http\Requests\UpdateAuthProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthSession;
use App\Models\LoginHistory;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\LoginAlertNotification;
use App\Services\ActivityLogger;
use App\Services\JwtTokenService;
use App\Services\UserProfileSync;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->is_active === false) {
            throw ValidationException::withMessages([
                'email' => [__('Account is deactivated.')],
            ]);
        }

        if ($user->pending_approval) {
            throw ValidationException::withMessages([
                'email' => [__('Your account is waiting for administrator approval.')],
            ]);
        }

        $jwt = JwtTokenService::fromConfig();
        $sessionId = (string) Str::uuid();
        $meta = $this->requestMeta($request);

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();
        $this->sendLoginAlerts($user, $request, 'password');

        return response()->json([
            'access_token' => $jwt->issueAccessToken($user, $sessionId),
            'refresh_token' => $jwt->issueRefreshToken($user, $sessionId, $meta),
            'token_type' => 'Bearer',
            'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
            'user' => new UserResource($user->load('roles', 'permissions', 'profile')),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $jwt = JwtTokenService::fromConfig();
        $payload = $jwt->refreshPayload($data['refresh_token']);
        if (! $payload) {
            return response()->json(['message' => 'Invalid refresh token.'], 401);
        }

        $user = User::query()->find((int) ($payload['sub'] ?? 0));
        if (! $user) {
            return response()->json(['message' => 'Invalid refresh token.'], 401);
        }

        if ($user->is_active === false) {
            return response()->json(['message' => __('Account is deactivated.')], 403);
        }

        $sessionId = (string) ($payload['sid'] ?? '');
        $jwt->revokeRefreshToken($data['refresh_token']);
        $meta = $this->requestMeta($request);
        $this->recordLogin($user, $request, 'refresh');

        return response()->json([
            'access_token' => $jwt->issueAccessToken($user, $sessionId ?: null),
            'refresh_token' => $jwt->issueRefreshToken($user, $sessionId ?: null, $meta),
            'token_type' => 'Bearer',
            'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['nullable', 'string'],
        ]);
        if (! empty($data['refresh_token'])) {
            JwtTokenService::fromConfig()->revokeRefreshToken($data['refresh_token']);
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    public function updateMe(UpdateAuthProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $profile = $validated['profile'] ?? null;
        unset($validated['profile']);

        if (array_key_exists('password', $validated) && $validated['password'] !== null && $validated['password'] !== '') {
            $user->password = Hash::make($validated['password']);
        }
        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }
        if ($user->isDirty()) {
            $user->save();
        }

        if (is_array($profile)) {
            UserProfileSync::sync($user->fresh(), $profile);
        }

        $user->load('roles', 'permissions', 'profile');
        ActivityLogger::log('profile.self_updated', $user);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->password = Hash::make($request->validated('password'));
        $user->save();
        ActivityLogger::log('profile.password_changed', $user);

        return response()->json(['message' => __('Password updated.')]);
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');

        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$file->getClientOriginalExtension();
        $originalPath = public_path('storage/uploads/users/original');
        $thumbPath = public_path('storage/uploads/users/thumbnails');
        if (! is_dir($originalPath)) {
            mkdir($originalPath, 0755, true);
        }
        if (! is_dir($thumbPath)) {
            mkdir($thumbPath, 0755, true);
        }

        Image::make($file->getRealPath())->save($originalPath.'/'.$filename, 90);
        Image::make($file->getRealPath())
            ->fit(300, 300, function ($constraint) {
                $constraint->upsize();
            })
            ->save($thumbPath.'/'.$filename, 85);

        UserProfileSync::sync($user->fresh(), ['photo' => $filename]);
        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Avatar uploaded successfully.'),
            'user' => new UserResource($user),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $photo = $user->profile?->photo;
        if ($photo) {
            $original = public_path('storage/uploads/users/original/'.$photo);
            $thumb = public_path('storage/uploads/users/thumbnails/'.$photo);
            if (is_file($original)) {
                @unlink($original);
            }
            if (is_file($thumb)) {
                @unlink($thumb);
            }
        }

        UserProfileSync::sync($user->fresh(), ['photo' => null]);
        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Avatar removed.'),
            'user' => new UserResource($user),
        ]);
    }

    public function uploadCover(Request $request): JsonResponse
    {
        $request->validate([
            'cover' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('cover');

        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$file->getClientOriginalExtension();
        $originalPath = public_path('storage/uploads/users/original');
        if (! is_dir($originalPath)) {
            mkdir($originalPath, 0755, true);
        }

        // Cover banner — wide crop, capped for fast loading.
        Image::make($file->getRealPath())
            ->fit(1600, 480, function ($constraint) {
                $constraint->upsize();
            })
            ->save($originalPath.'/'.$filename, 85);

        // Setting an image clears any solid colour.
        UserProfileSync::sync($user->fresh(), ['cover_photo' => $filename, 'cover_color' => null]);
        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Cover updated successfully.'),
            'user' => new UserResource($user),
        ]);
    }

    public function deleteCover(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cover = $user->profile?->cover_photo;
        if ($cover && ! str_starts_with((string) $cover, 'http')) {
            $original = public_path('storage/uploads/users/original/'.$cover);
            if (is_file($original)) {
                @unlink($original);
            }
        }

        UserProfileSync::sync($user->fresh(), ['cover_photo' => null]);
        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Cover removed.'),
            'user' => new UserResource($user),
        ]);
    }

    public function connectGoogle(ConnectGoogleRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->resolveGoogleIdentity($request->validated());

        $exists = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($identity) {
                $q->where('google_id', $identity['google_id']);
                if (! empty($identity['email'])) {
                    $q->orWhere('email', $identity['email']);
                }
            })
            ->exists();

        if ($exists) {
            return response()->json(['message' => __('This Google account is already linked to another user.')], 422);
        }

        $user->google_id = $identity['google_id'];
        if (! empty($identity['avatar'])) {
            $user->avatar = $identity['avatar'];
        }
        if (! empty($identity['name']) && $user->name !== $identity['name']) {
            $user->name = $identity['name'];
        }
        $user->save();

        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Google account connected.'),
            'user' => new UserResource($user),
        ]);
    }

    public function disconnectGoogle(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->google_id) {
            return response()->json(['message' => __('Google account is not connected.')], 422);
        }

        $user->google_id = null;
        $user->save();
        $user->load('roles', 'permissions', 'profile');

        return response()->json([
            'message' => __('Google account disconnected.'),
            'user' => new UserResource($user),
        ]);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $paginator = LoginHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (LoginHistory $h) => [
                'id' => $h->id,
                'login_method' => $h->login_method,
                'ip_address' => $h->ip_address,
                'user_agent' => $h->user_agent,
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $jwt = JwtTokenService::fromConfig();
        $token = $this->accessTokenFromRequest($request);
        $currentSessionId = $token ? $jwt->accessSessionId($token) : null;

        $sessions = AuthSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->get();

        return response()->json([
            'data' => $sessions->map(fn (AuthSession $s) => [
                'id' => $s->id,
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'device_name' => $s->device_name,
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'expires_at' => $s->expires_at?->toIso8601String(),
                'current' => $currentSessionId !== null && $s->id === $currentSessionId,
            ])->values(),
            'current_session_id' => $currentSessionId,
        ]);
    }

    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $jwt = JwtTokenService::fromConfig();
        $token = $this->accessTokenFromRequest($request);
        $currentSessionId = $token ? $jwt->accessSessionId($token) : null;
        if ($currentSessionId !== null && $sessionId === $currentSessionId) {
            return response()->json(['message' => __('Cannot revoke current session from this action.')], 422);
        }

        $ok = $jwt->revokeSessionById($user, $sessionId);
        if (! $ok) {
            return response()->json(['message' => __('Session not found.')], 404);
        }

        return response()->json(['message' => __('Session revoked.')]);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $jwt = JwtTokenService::fromConfig();
        $token = $this->accessTokenFromRequest($request);
        $currentSessionId = $token ? $jwt->accessSessionId($token) : null;
        $count = $jwt->revokeOtherSessions($user, $currentSessionId);

        return response()->json([
            'message' => __('Other sessions revoked.'),
            'revoked' => $count,
        ]);
    }

    /**
     * Public: OAuth client ID for Sign in with Google (GIS) in the SPA.
     * Uses merged config (database settings or .env). Client ID is not secret.
     */
    public function googleConfig(): JsonResponse
    {
        $id = trim((string) (config('services.google.client_id') ?? ''));

        return response()->json([
            'client_id' => $id !== '' ? $id : null,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'registration_role' => ['required', Rule::in(['Staff', 'Supplier', 'Merchant'])],
        ]);

        $approver = app(\App\Services\Users\RegistrationApprover::class);
        // Merchants and Suppliers are customers onboarding themselves, so they
        // start using the platform right away. Staff carries internal access and
        // still waits for an administrator.
        $selfService = $approver->isSelfServiceRole($data['registration_role']);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
            'pending_approval' => true,
            'registration_role' => $data['registration_role'],
        ]);

        Profile::query()->create([
            'user_id' => $user->id,
            'username' => 'user_'.$user->id,
            'gender' => 'male',
            'active' => 0,
            'private' => 0,
            'online' => 1,
        ]);

        if ($selfService) {
            $approver->approve(
                $user,
                (string) $approver->normalizeRole($data['registration_role']),
                'user.self_registered',
            );
            $user->refresh();
        }

        $user->load('roles', 'permissions', 'profile');
        $jwt = JwtTokenService::fromConfig();
        $sessionId = (string) Str::uuid();
        $meta = $this->requestMeta($request);

        return response()->json([
            'pending_approval' => ! $selfService,
            'message' => $selfService
                ? __('Welcome aboard! Your account is ready.')
                : __('Your registration was received. An administrator must approve your account before you can sign in.'),
            'access_token' => $jwt->issueAccessToken($user, $sessionId),
            'refresh_token' => $jwt->issueRefreshToken($user, $sessionId, $meta),
            'token_type' => 'Bearer',
            'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
            'user' => new UserResource($user),
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => __('We have emailed your password reset link.')]);
        }

        return response()->json(['message' => __('Unable to send reset link. Check the email address.')], 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => __('Your password has been reset.')]);
        }

        return response()->json(['message' => __($status)], 422);
    }

    /**
     * SPA Google Sign-In: pass OAuth access_token from GIS, or id_token (JWT from credential response).
     * New accounts require registration_role Staff|Supplier and stay pending until an admin approves.
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['required_without:id_token', 'string'],
            'id_token' => ['required_without:access_token', 'string'],
            'registration_role' => ['nullable', Rule::in(['Staff', 'Supplier', 'Merchant'])],
        ]);

        try {
            $identity = $this->resolveGoogleIdentity($data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['message' => __('Google authentication failed.')], 422);
        }

        $email = $identity['email'];
        $googleId = $identity['google_id'];
        $name = $identity['name'];
        $avatar = $identity['avatar'];

        if (! $email) {
            return response()->json(['message' => __('Google did not return an email.')], 422);
        }

        $emailNormalized = strtolower(trim($email));

        $user = User::query()
            ->withTrashed()
            ->where(function ($q) use ($googleId, $emailNormalized) {
                if ($googleId !== '') {
                    $q->where('google_id', $googleId);
                }
                if ($emailNormalized !== '') {
                    if ($googleId !== '') {
                        $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$emailNormalized]);
                    } else {
                        $q->whereRaw('LOWER(TRIM(email)) = ?', [$emailNormalized]);
                    }
                }
            })
            ->first();

        $this->restoreTrashedUserForGoogleSignup($user, $data);

        if (! $user) {
            if (empty($data['registration_role'])) {
                return response()->json([
                    'message' => __('Please choose whether you are registering as staff or supplier.'),
                    'code' => 'registration_role_required',
                ], 422);
            }

            try {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $emailNormalized,
                    'password' => Hash::make(Str::random(40)),
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'email_verified_at' => now(),
                    'pending_approval' => true,
                    'registration_role' => $data['registration_role'],
                ]);

                Profile::query()->create([
                    'user_id' => $user->id,
                    'username' => 'user_'.$user->id,
                    'gender' => 'male',
                    'active' => 0,
                    'private' => 0,
                    'online' => 1,
                ]);

                return response()->json([
                    'pending_approval' => true,
                    'message' => __('Your registration was received. An administrator must approve your account before you can sign in.'),
                ], 201);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyException($e)) {
                    throw $e;
                }

                $user = User::query()
                    ->withTrashed()
                    ->where(function ($q) use ($googleId, $emailNormalized) {
                        if ($googleId !== '') {
                            $q->where('google_id', $googleId);
                        }
                        if ($emailNormalized !== '') {
                            if ($googleId !== '') {
                                $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$emailNormalized]);
                            } else {
                                $q->whereRaw('LOWER(TRIM(email)) = ?', [$emailNormalized]);
                            }
                        }
                    })
                    ->first();

                if (! $user) {
                    throw $e;
                }
                $this->restoreTrashedUserForGoogleSignup($user, $data);
            }
        }

        if ($googleId !== '' && $user->google_id !== $googleId) {
            $user->google_id = $googleId;
        }
        if ($avatar) {
            $user->avatar = $avatar;
        }
        if ($name !== '' && $user->name !== $name) {
            $user->name = $name;
        }
        $user->save();

        if ($user->is_active === false) {
            return response()->json(['message' => __('Account is deactivated.')], 403);
        }

        $user->load('roles', 'permissions', 'profile');
        $jwt = JwtTokenService::fromConfig();
        $sessionId = (string) Str::uuid();
        $meta = $this->requestMeta($request);

        if ($user->pending_approval) {
            return response()->json([
                'pending_approval' => true,
                'message' => __('Your account is waiting for administrator approval.'),
                'access_token' => $jwt->issueAccessToken($user, $sessionId),
                'refresh_token' => $jwt->issueRefreshToken($user, $sessionId, $meta),
                'token_type' => 'Bearer',
                'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
                'user' => new UserResource($user),
            ], 200);
        }

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $this->sendLoginAlerts($user, $request, 'google');

        return response()->json([
            'access_token' => $jwt->issueAccessToken($user, $sessionId),
            'refresh_token' => $jwt->issueRefreshToken($user, $sessionId, $meta),
            'token_type' => 'Bearer',
            'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * @param  'password'|'google'  $method
     */
    private function sendLoginAlerts(User $user, Request $request, string $method): void
    {
        if (! in_array($method, ['password', 'google'], true)) {
            $this->recordLogin($user, $request, $method);

            return;
        }

        $previous = LoginHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->recordLogin($user, $request, $method);

        $isNewDevice = ! $previous || (string) $previous->ip_address !== (string) $request->ip();
        $ua = (string) $request->userAgent();
        $uaShort = strlen($ua) > 120 ? substr($ua, 0, 117).'...' : $ua;

        $user->notify(new LoginAlertNotification($isNewDevice, (string) $request->ip(), $uaShort, $method));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{google_id:string,email:?string,name:string,avatar:?string}
     */
    private function resolveGoogleIdentity(array $data): array
    {
        if (! empty($data['access_token'] ?? null)) {
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($data['access_token']);
            $googleId = (string) $googleUser->getId();
            $email = $googleUser->getEmail();
            $name = $googleUser->getName() ?: ($email ? strstr($email, '@', true) : 'User');
            $avatar = $googleUser->getAvatar();

            return [
                'google_id' => $googleId,
                'email' => $email,
                'name' => (string) $name,
                'avatar' => $avatar,
            ];
        }

        $info = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);
        if (! $info->successful()) {
            throw ValidationException::withMessages([
                'id_token' => [__('Invalid Google token.')],
            ]);
        }
        $payload = $info->json();
        $expectedAud = (string) config('services.google.client_id');
        if ($expectedAud === '' || ($payload['aud'] ?? '') !== $expectedAud) {
            throw ValidationException::withMessages([
                'id_token' => [__('Invalid token audience.')],
            ]);
        }

        return [
            'google_id' => (string) ($payload['sub'] ?? ''),
            'email' => $payload['email'] ?? null,
            'name' => (string) ($payload['name'] ?? (($payload['email'] ?? null) ? strstr((string) $payload['email'], '@', true) : 'User')),
            'avatar' => $payload['picture'] ?? null,
        ];
    }

    /**
     * @return array{ip_address: ?string, user_agent: ?string, device_name: ?string}
     */
    private function requestMeta(Request $request): array
    {
        $userAgent = $request->userAgent();

        return [
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'device_name' => $this->deviceNameFromUserAgent($userAgent),
        ];
    }

    private function recordLogin(User $user, Request $request, string $method): void
    {
        LoginHistory::query()->create([
            'user_id' => $user->id,
            'login_method' => $method,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function accessTokenFromRequest(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * Soft-deleted rows still reserve unique email/google_id. Google proves email ownership — restore
     * and re-apply pending registration when the SPA sends Staff|Supplier.
     */
    private function restoreTrashedUserForGoogleSignup(?User $user, array $data): void
    {
        if ($user === null || ! $user->trashed()) {
            return;
        }

        $user->restore();

        if (! empty($data['registration_role'])) {
            $user->pending_approval = true;
            $user->registration_role = $data['registration_role'];
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $state === '23000'
            || $code === 1062
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    private function deviceNameFromUserAgent(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        $uaLower = strtolower($ua);
        $platform = 'Unknown';
        if (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ios')) {
            $platform = 'iPhone';
        } elseif (str_contains($uaLower, 'ipad')) {
            $platform = 'iPad';
        } elseif (str_contains($uaLower, 'android')) {
            $platform = 'Android';
        } elseif (str_contains($uaLower, 'windows')) {
            $platform = 'Windows';
        } elseif (str_contains($uaLower, 'macintosh') || str_contains($uaLower, 'mac os')) {
            $platform = 'macOS';
        } elseif (str_contains($uaLower, 'linux')) {
            $platform = 'Linux';
        }

        $browser = 'Browser';
        if (str_contains($uaLower, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($uaLower, 'chrome/')) {
            $browser = 'Chrome';
        } elseif (str_contains($uaLower, 'safari/') && ! str_contains($uaLower, 'chrome/')) {
            $browser = 'Safari';
        } elseif (str_contains($uaLower, 'firefox/')) {
            $browser = 'Firefox';
        }

        return trim($platform.' · '.$browser);
    }
}
