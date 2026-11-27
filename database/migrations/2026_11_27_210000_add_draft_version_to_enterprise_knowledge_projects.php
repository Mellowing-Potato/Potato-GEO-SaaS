<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('draft_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table) {
            $table->dropColumn('draft_version');
        });
    }
};
