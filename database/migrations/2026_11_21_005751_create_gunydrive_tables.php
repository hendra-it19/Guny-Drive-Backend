<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('storage_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quota_bytes')->default(107374182400);
            $table->unsignedBigInteger('max_file_bytes')->default(2147483648);
            $table->unsignedBigInteger('physical_reserve_bytes')->default(268435456);
            $table->unsignedSmallInteger('trash_retention_days')->default(30);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('vault_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('passphrase_hash');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('passphrase_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('platform', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('space', 16)->default('regular');
            $table->string('name');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'space', 'parent_id', 'deleted_at']);
        });

        Schema::create('drive_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('folder_id')->nullable();
            $table->string('space', 16)->default('regular');
            $table->string('name');
            $table->string('object_key')->unique();
            $table->string('thumbnail_key')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('mime_type', 191);
            $table->string('category', 32)->default('other');
            $table->timestamp('media_taken_at')->nullable();
            $table->uuid('source_device_id')->nullable();
            $table->string('source_asset_id')->nullable();
            $table->string('source_revision')->nullable();
            $table->string('status', 16)->default('ready');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('purge_after_at')->nullable();
            $table->timestamps();

            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->foreign('source_device_id')->references('id')->on('devices')->nullOnDelete();
            $table->index(['user_id', 'space', 'folder_id', 'deleted_at']);
            $table->index(['user_id', 'space', 'category', 'deleted_at']);
            $table->index(['user_id', 'space', 'deleted_at', 'purge_after_at']);
        });

        Schema::create('source_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id')->nullable();
            $table->string('source_asset_id');
            $table->string('source_revision');
            $table->string('disposition', 32);
            $table->uuid('drive_file_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id', 'source_asset_id', 'source_revision'], 'source_markers_unique');
        });

        Schema::create('integration_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('template', 32);
            $table->uuid('folder_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('api_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_type', 16);
            $table->string('label');
            $table->uuid('device_id')->nullable();
            $table->uuid('integration_endpoint_id')->nullable();
            $table->json('scopes');
            $table->string('token_prefix', 24);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('integration_endpoint_id')->references('id')->on('integration_endpoints')->nullOnDelete();
            $table->index(['user_id', 'client_type', 'revoked_at']);
        });

        Schema::create('vault_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('api_credential_id');
            $table->string('token_hash', 64)->unique();
            $table->dateTime('last_activity_at');
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('api_credential_id')->references('id')->on('api_credentials')->cascadeOnDelete();
            $table->index(['user_id', 'api_credential_id', 'revoked_at']);
        });

        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('api_credential_id');
            $table->uuid('device_id')->nullable();
            $table->string('space', 16)->default('regular');
            $table->uuid('folder_id')->nullable();
            $table->string('idempotency_key');
            $table->string('filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('total_size_bytes');
            $table->string('expected_sha256', 64);
            $table->unsignedBigInteger('received_offset')->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->string('staging_key');
            $table->timestamp('media_taken_at')->nullable();
            $table->string('source_asset_id')->nullable();
            $table->string('source_revision')->nullable();
            $table->string('status', 16)->default('open');
            $table->uuid('completed_file_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['api_credential_id', 'idempotency_key'], 'upload_sessions_idempotency');
            $table->foreign('api_credential_id')->references('id')->on('api_credentials')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->foreign('completed_file_id')->references('id')->on('drive_files')->nullOnDelete();
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('api_credential_id')->nullable();
            $table->uuid('request_id')->unique();
            $table->string('method', 10);
            $table->string('route_template', 191);
            $table->unsignedSmallInteger('status_code');
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'status_code']);
            $table->index(['route_template', 'created_at']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('action', 64);
            $table->string('outcome', 24);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('job_name', 64)->unique();
            $table->timestamp('last_ran_at');
            $table->string('status', 24)->default('ok');
            $table->string('summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('upload_sessions');
        Schema::dropIfExists('vault_sessions');
        Schema::dropIfExists('api_credentials');
        Schema::dropIfExists('integration_endpoints');
        Schema::dropIfExists('source_markers');
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('vault_configs');
        Schema::dropIfExists('storage_policies');
    }
};
