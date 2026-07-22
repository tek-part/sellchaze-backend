<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invitations')->whereNotNull('registered_at')->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        //
    }
};
