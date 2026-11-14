<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Tenant;
use App\Services\Publishing\SiteDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 租户托管文章公读页（阶段 3.5）。
 *
 * 公开访问，无需鉴权；未发布/私有/不存在一律 404。
 *
 * 访问采集（一期 + X4）：真实 status_code 由 ReadingPageVisitMiddleware（terminable）
 * 在响应发完后从 $response 取得，404 也入库（爬虫撞死链是有效信号）。本 controller
 * 仅在成功渲染时把 article/project id 写入 request attribute，供中间件读取；采集失败
 * 静默，绝不影响页面可用性。外站分发内容的信标上报划归二期（GeoFlowBeaconController）。
 */
class ReadingPageController extends Controller
{
    public function __construct(
        private readonly SiteDeliveryService $delivery,
    ) {}

    public function show(Request $request, string $tenantSlug, string $slug): View|Response
    {
        $data = $this->delivery->resolve($tenantSlug, $slug);

        if ($data === null) {
            abort(404);
        }

        /** @var Article $article */
        $article = $data['article'];
        /** @var Tenant $tenant */
        $tenant = $data['tenant'];

        // 供 ReadingPageVisitMiddleware 读取（仅成功渲染路径；404 时 article 为 null）
        $request->attributes->set('reading_article_id', (int) $article->id);
        $request->attributes->set('reading_project_id', $article->project_id !== null ? (int) $article->project_id : null);

        return response()->view('reading.article', $data)
            ->withHeaders([
                'Cache-Control' => 'public, max-age=300',
                'X-Robots-Tag' => $data['seo']['external_canonical'] ? 'noindex, follow' : 'index, follow',
            ]);
    }
}
