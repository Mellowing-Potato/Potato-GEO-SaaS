<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_publication_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('manual_publication_accounts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'account_id']);
            $table->index(['tenant_id', 'project_id']);
        });

        // Only completed, explicitly attributed historical posts establish a project/account choice.
        $completed = DB::table('manual_publications as publication')
            ->join('articles as article', 'article.id', '=', 'publication.article_id')
            ->join('projects as project', 'project.id', '=', 'article.project_id')
            ->join('manual_publication_accounts as account', 'account.id', '=', 'publication.account_id')
            ->join('manual_publication_personas as persona', 'persona.id', '=', 'account.persona_id')
            ->where('publication.type', 'post')->where('publication.status', 'completed')
            ->whereNotNull('publication.completion_url')->where('publication.completion_url', '!=', '')
            ->whereNotNull('publication.completed_at')
            ->whereColumn('publication.tenant_id', 'article.tenant_id')
            ->whereColumn('project.tenant_id', 'article.tenant_id')
            ->whereColumn('account.tenant_id', 'article.tenant_id')
            ->whereColumn('persona.tenant_id', 'article.tenant_id')
            ->whereColumn('account.persona_id', 'publication.persona_id')
            ->whereColumn('account.platform', 'publication.platform')
            ->where('account.is_active', true)->where('persona.is_active', true)
            ->whereNull('article.deleted_at')->whereNull('project.deleted_at')
            ->select('article.tenant_id', 'article.project_id', 'account.id')
            ->selectRaw('CURRENT_TIMESTAMP, CURRENT_TIMESTAMP')->distinct();
        DB::table('project_publication_accounts')->insertUsing(
            ['tenant_id', 'project_id', 'account_id', 'created_at', 'updated_at'], $completed,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_publication_accounts');
    }
};
