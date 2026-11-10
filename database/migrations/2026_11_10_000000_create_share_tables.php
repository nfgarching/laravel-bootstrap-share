<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lokale Spiegelung der Sendungen im Portal, verknüpft mit dem Kontext (z. B. der Akte).
        // Betreff, Nachricht, E-Mail-Adressen, Links und Passwörter sind verschlüsselt (APP_KEY).
        Schema::create('share_shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('portal_id')->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('context_key', 191)->nullable()->index();
            $table->text('context')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('subject');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('max_downloads');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('share_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_shipment_id')->constrained()->cascadeOnDelete();
            $table->uuid('portal_id')->nullable()->index();
            $table->text('email');
            $table->text('name')->nullable();
            $table->boolean('password_protected')->default(false);
            $table->text('url')->nullable();
            $table->text('password')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('first_downloaded_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->boolean('locked')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('share_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_shipment_id')->constrained()->cascadeOnDelete();
            $table->uuid('portal_id')->nullable()->index();
            $table->string('disk', 50);
            $table->text('path');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->timestamps();
        });

        // Protokoll aus dem Portal (GET /events). Die höchste portal_event_id ist der Cursor.
        Schema::create('share_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_event_id')->unique();
            $table->foreignId('share_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50)->index();
            $table->uuid('shipment_portal_id')->nullable()->index();
            $table->uuid('recipient_portal_id')->nullable();
            $table->uuid('file_portal_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_events');
        Schema::dropIfExists('share_files');
        Schema::dropIfExists('share_recipients');
        Schema::dropIfExists('share_shipments');
    }
};
