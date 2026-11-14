<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_publishing_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('publishing_channel_id');
            $table->timestamps();

            $table->unique(['project_id', 'publishing_channel_id'], 'proj_channel_unique');
            $table->index(['tenant_id', 'project_id'], 'tenant_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_publishing_channels');
    }
};
