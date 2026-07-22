<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('order_tickets')->cascadeOnDelete();
            $table->string('action', 100); // return_slip, manual_return, warehouse_adjustment, etc
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_actions');
    }
};
