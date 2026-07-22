<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class GoogleSettingsSeeder extends Seeder
{
    /**
     * Seed Google OAuth credentials into the settings table (used by
     * GoogleSettingsApiController + Socialite).
     *
     * Credentials come from configuration only (config/services.php → env
     * GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET). Nothing is hardcoded: a secret
     * committed to source is a leak regardless of how it is labelled. When the
     * variables are unset the seeder skips rather than inventing values, so a
     * fresh environment is told to configure them instead of silently seeding a
     * broken OAuth setup.
     *
     * @return void
     */
    public function run()
    {
        $clientId = trim((string) config('services.google.client_id', ''));
        $clientSecret = trim((string) config('services.google.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            $this->command->warn('Skipping Google settings: set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env');

            return;
        }

        Setting::set('google_client_id', $clientId);
        Setting::set('google_client_secret', $clientSecret);
        Setting::clearCache();

        $this->command->info('Google OAuth settings seeded (settings: google_client_id, google_client_secret).');
        $this->command->line('Google Cloud Console: add Authorized JavaScript origin http://localhost:5173 and redirect URI '.url('/auth/google/callback'));
    }
}
