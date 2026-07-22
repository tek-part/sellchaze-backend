<?php

namespace App\Services;

use App\Models\User;

class NotificationChannelResolver
{
    /**
     * @return array<string, array{label_key: string, defaults: array{in_app: bool, email: bool}, roles: list<string>}>
     */
    public function catalog(): array
    {
        return config('notification_preferences.keys', []);
    }

    /**
     * @return list<string>
     */
    public function applicableKeysForUser(User $user): array
    {
        $names = $user->getRoleNames()->map(fn ($n) => (string) $n)->all();
        $out = [];
        foreach ($this->catalog() as $key => $meta) {
            $roles = $meta['roles'] ?? [];
            if ($roles === []) {
                continue;
            }
            foreach ($roles as $role) {
                if (in_array($role, $names, true)) {
                    $out[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{in_app: bool, email: bool}
     */
    public function effectivePreference(User $user, string $key): array
    {
        $meta = $this->catalog()[$key] ?? null;
        if (! is_array($meta)) {
            return ['in_app' => true, 'email' => false];
        }
        $defaults = $meta['defaults'] ?? ['in_app' => true, 'email' => false];
        $override = data_get($user->notification_preferences, $key, []);
        if (! is_array($override)) {
            $override = [];
        }

        $inApp = array_key_exists('in_app', $override)
            ? (bool) $override['in_app']
            : (bool) ($defaults['in_app'] ?? true);

        if ($key === 'login_security') {
            $email = array_key_exists('email', $override)
                ? (bool) $override['email']
                : (bool) ($user->notify_login_email ?? false);
        } else {
            $email = array_key_exists('email', $override)
                ? (bool) $override['email']
                : (bool) ($defaults['email'] ?? false);
        }

        return ['in_app' => $inApp, 'email' => $email];
    }

    /**
     * @return list<string>
     */
    public function channels(User $user, string $key): array
    {
        $pref = $this->effectivePreference($user, $key);
        $channels = [];
        if ($pref['in_app']) {
            $channels[] = 'database';
        }
        if ($pref['email'] && $this->userHasEmail($user)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @param  array<string, array{in_app?: bool, email?: bool}>  $incoming
     */
    public function mergeOverrides(User $user, array $incoming): array
    {
        $prefs = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $allowed = $this->applicableKeysForUser($user);

        foreach ($incoming as $key => $vals) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }
            if (! is_array($vals)) {
                continue;
            }
            $meta = $this->catalog()[$key] ?? null;
            if (! is_array($meta)) {
                continue;
            }
            $defaults = $meta['defaults'] ?? ['in_app' => true, 'email' => false];
            $current = isset($prefs[$key]) && is_array($prefs[$key]) ? $prefs[$key] : [];

            foreach (['in_app', 'email'] as $field) {
                if (! array_key_exists($field, $vals)) {
                    continue;
                }
                $next = (bool) $vals[$field];
                $def = (bool) ($defaults[$field] ?? false);
                if ($next === $def) {
                    unset($current[$field]);
                } else {
                    $current[$field] = $next;
                }
            }

            if ($current === []) {
                unset($prefs[$key]);
            } else {
                $prefs[$key] = $current;
            }
        }

        return $prefs;
    }

    public function syncNotifyLoginEmailFromPreferences(User $user): void
    {
        $user->load('roles');
        $eff = $this->effectivePreference($user, 'login_security');
        $user->notify_login_email = $eff['email'];
    }

    /**
     * @return list<array{key: string, label_key: string, in_app: bool, email: bool}>
     */
    public function preferencesPayloadForUser(User $user): array
    {
        $rows = [];
        foreach ($this->applicableKeysForUser($user) as $key) {
            $meta = $this->catalog()[$key] ?? [];
            $eff = $this->effectivePreference($user, $key);
            $rows[] = [
                'key' => $key,
                'label_key' => (string) ($meta['label_key'] ?? $key),
                'in_app' => $eff['in_app'],
                'email' => $eff['email'],
            ];
        }

        return $rows;
    }

    private function userHasEmail(User $user): bool
    {
        $email = trim((string) ($user->email ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
