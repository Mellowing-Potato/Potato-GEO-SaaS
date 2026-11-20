<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_connection_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('slot', ['text', 'vision']);
            $table->enum('provider', ['magpie']);
            $table->string('label', 100)->nullable();
            $table->string('target_alias', 100);
            $table->string('target_revision', 64);
            $table->string('base_url', 2048);
            $table->string('model', 512)->nullable();
            $table->enum('auth_mode', ['gateway_key', 'loopback']);
            $table->text('credential')->nullable();
            $table->json('options')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slot']);
            $table->unique(['id', 'tenant_id', 'slot'], 'model_profiles_binding_identity');
        });

        Schema::create('model_slot_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('slot', ['text', 'vision']);
            $table->enum('source_type', ['legacy', 'profile', 'disabled']);
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slot']);
            $table->foreign(['profile_id', 'tenant_id', 'slot'], 'model_binding_profile_identity')
                ->references(['id', 'tenant_id', 'slot'])->on('model_connection_profiles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_slot_bindings');
        Schema::dropIfExists('model_connection_profiles');
    }
};
