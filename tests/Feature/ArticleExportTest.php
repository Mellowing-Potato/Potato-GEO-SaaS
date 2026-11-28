<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;
use ZipArchive;

class ArticleExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_docx_download_is_a_word_archive_containing_formatted_current_article(): void
    {
        $body = "# 中文 Word 导出\n\n正文含 **重点** 与 A & B，以及 <价格>。\n\n## 服务步骤\n\n- 第一项\n- 第二项\n\n[官网](https://example.com/geo)\n\n| 项目 | 数量 |\n| --- | --- |\n| 商品甲 | 12 |\n\n![示意图](https://images.example.test/picture.png)";
        [$tenant, $admin, $article] = $this->article('word-export', $body);
        $article->update(['summary' => '这是一段独立摘要。']);
        Sanctum::actingAs($admin, ['*']);
        $previousEscaping = Settings::isOutputEscapingEnabled();

        $response = $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/export/docx")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertStringContainsString('.docx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $response->getContent());
        $this->assertSame($previousEscaping, Settings::isOutputEscapingEnabled());
        $path = tempnam(sys_get_temp_dir(), 'geo-word-test-');
        $zip = new ZipArchive;
        try {
            file_put_contents($path, $response->getContent());
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
            $xml = $zip->getFromName('word/document.xml');
            $dom = new DOMDocument;
            $this->assertTrue($dom->loadXML($xml));
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $text = implode('', array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//w:t'))));
            foreach (['中文 Word 导出', '摘要', '这是一段独立摘要。', '正文含', '重点', 'A & B', '<价格>', '服务步骤', '第一项', '第二项', '官网', '商品甲', '12', '［图片：示意图］'] as $expected) {
                $this->assertStringContainsString($expected, $text);
            }
            $this->assertSame(1, mb_substr_count($text, '这是一段独立摘要。'));
            $this->assertStringContainsString('https://example.com/geo', (string) $zip->getFromName('word/_rels/document.xml.rels'));
            $this->assertGreaterThan(0, $xpath->query('//w:b')->length);
            $this->assertGreaterThan(0, $xpath->query('//w:tbl')->length);
            $this->assertStringNotContainsString('**重点**', $text);
            $this->assertStringNotContainsString('images.example.test', (string) $zip->getFromName('word/_rels/document.xml.rels'));
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    public function test_existing_download_endpoint_keeps_markdown_contract(): void
    {
        $body = "# 兼容正文\n\n保留 **Markdown**。";
        [$tenant, $admin, $article] = $this->article('markdown-export', $body);
        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $this->assertSame($body, $response->getContent());
        $this->assertStringContainsString('.md', $response->headers->get('Content-Disposition'));
    }

    public function test_word_export_omits_summary_heading_when_summary_is_empty(): void
    {
        $bytes = app(\App\Services\Content\ArticleWordExporter::class)
            ->toDocx('空摘要标题', "第一段。\n\n- 列表项", '');
        $path = tempnam(sys_get_temp_dir(), 'geo-word-empty-');
        $zip = new ZipArchive;
        try {
            file_put_contents($path, $bytes);
            $this->assertTrue($zip->open($path));
            $xml = (string) $zip->getFromName('word/document.xml');
            $this->assertStringNotContainsString('<w:t>摘要</w:t>', $xml);
            $this->assertStringContainsString('空摘要标题', $xml);
            $this->assertStringContainsString('列表项', $xml);
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    public function test_word_export_requires_login_article_scope_and_current_tenant(): void
    {
        [$tenant, $admin, $article] = $this->article('export-owner', '仅限本租户');
        $url = "/api/v1/articles/{$article->id}/export/docx";
        $this->forTenant($tenant)->getJson($url)->assertUnauthorized();

        Sanctum::actingAs($admin, ['analytics:read']);
        $this->forTenant($tenant)->getJson($url)->assertForbidden();

        [$otherTenant, $otherAdmin] = $this->article('export-other', '其他租户');
        Sanctum::actingAs($otherAdmin, ['*']);
        $this->forTenant($otherTenant)->getJson($url)->assertNotFound();
    }

    public function test_wechat_export_contains_rendered_content_without_executable_article_html(): void
    {
        [$tenant, $admin, $article] = $this->article('wechat-content', "# 微信标题\n\n正文 **重点** & 补充说明。\n\n<script>alert(1)</script>\n\n[危险链接](javascript:alert%281%29)");
        Sanctum::actingAs($admin, ['*']);
        $response = $this->forTenant($tenant)
            ->getJson("/api/v1/articles/{$article->id}/export/wechat")
            ->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('微信标题', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('正文 重点 & 补充说明。', $response->json('plain_text'));
    }

    public function test_preview_matches_public_markdown_rules_for_lists_links_and_unsafe_input(): void
    {
        $markdown = "## 服务说明\n\n第一段。\n\n- 资料整理\n- 审核发布\n\n[官网](https://example.com/geo)\n\n<script>alert(1)</script>\n\n[危险](javascript:alert%281%29)";
        [$tenant, $admin, $article] = $this->article('preview-markdown', $markdown);
        Sanctum::actingAs($admin, ['*']);
        $html = $this->forTenant($tenant)->getJson("/api/v1/articles/{$article->id}/preview")
            ->assertOk()->assertJsonPath('version', $article->fresh()->version)->json('html');
        $this->assertSame(app(\App\Services\Content\ArticleMarkdownRenderer::class)->renderMarkdown($markdown), $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('href="https://example.com/geo"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $article->update(['body_markdown' => "旧纯文本正文。\n第二行。"]);
        $this->assertStringContainsString('旧纯文本正文。',
            $this->forTenant($tenant)->getJson("/api/v1/articles/{$article->id}/preview")->assertOk()->json('html'));
    }

    private function article(string $slug, string $body): array
    {
        $tenant = Tenant::query()->create(['subdomain' => $slug, 'name' => $slug, 'plan' => 'points', 'status' => 'active']);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'role' => 'admin',
        ]);
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create(['owner_id' => $admin->id, 'name' => $slug, 'brand_name' => $slug, 'settings' => []]);
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $admin->id,
                'title' => '中文 Word 导出',
                'body_markdown' => $body,
                'content_type' => 'general',
                'status' => 'draft',
                'source_type' => 'manual',
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $admin, $article];
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
