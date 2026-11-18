<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upload-Anforderungen der Kanzlei (Spiegel der Anforderungen im Portal)
        Schema::create('share_upload_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('portal_id')->nullable()->unique();
            $table->foreignId('share_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('context_key', 191)->nullable()->index();
            $table->text('context')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('subject');
            $table->text('message')->nullable();
            $table->text('email');
            $table->text('name')->nullable();
            $table->text('url')->nullable();
            $table->text('password')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Abgeholte Uploads, entschlüsselt und abgelegt
        Schema::create('share_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('portal_id')->unique();
            $table->foreignId('share_upload_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context_key', 191)->nullable()->index();
            $table->text('context')->nullable();
            $table->uuid('batch')->index();
            $table->string('via', 20)->default('link');
            $table->text('sender_email');
            $table->text('sender_name')->nullable();
            $table->text('subject')->nullable();
            $table->text('message')->nullable();
            $table->string('name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('disk', 50)->nullable();
            $table->text('path')->nullable();
            $table->unsignedBigInteger('file_id')->nullable()->index();
            $table->string('status', 20)->default('stored')->index();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_uploads');
        Schema::dropIfExists('share_upload_requests');
    }
};
