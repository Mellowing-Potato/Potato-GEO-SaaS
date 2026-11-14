<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('slug', 180)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_for_review_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'slug']);
            $table->index('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category']);
            $table->dropIndex(['tenant_id', 'slug']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'slug',
                'category',
                'seo_title',
                'seo_description',
                'canonical_url',
                'submitted_for_review_at',
                'reviewed_at',
                'review_notes',
            ]);
        });
    }
};
