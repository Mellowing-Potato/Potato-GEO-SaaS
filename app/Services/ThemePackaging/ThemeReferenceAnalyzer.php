<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplication/ThemeReferenceAnalyzer.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use App\Models\SiteThemeReplication;

class ThemeReferenceAnalyzer
{
    /**
     * @param  array<string, mixed>  $references
     * @return array<string, mixed>
     */
    public function analyze(SiteThemeReplication $replication, array $references): array
    {
        $pages = is_array($references['pages'] ?? null) ? $references['pages'] : [];
        $cssText = $this->collectCssText($pages);

        return [
            'source_domains' => $replication->sourceDomains(),
            'style_preference' => (string) $replication->style_preference,
            'pages' => $this->summarizePages($pages),
            'tokens' => [
                'colors' => $this->extractColors($cssText),
                'font_families' => $this->extractFonts($cssText),
                'radius' => $this->extractMostCommonCssValues($cssText, 'border-radius'),
                'spacing' => $this->extractMostCommonCssValues($cssText, 'padding|margin'),
            ],
            'layout' => [
                'has_header' => $this->componentCount($pages, 'header') > 0,
                'has_navigation' => $this->componentCount($pages, 'nav') > 0,
                'has_sidebar' => $this->looksLikeSidebar($pages),
                'uses_cards_or_grid' => $this->componentCount($pages, 'semantic_class_hits') > 0,
                'article_density' => $this->componentCount($pages, 'article'),
            ],
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function collectCssText(array $pages): string
    {
        $chunks = [];
        foreach ($pages as $page) {
            foreach ((array) ($page['css'] ?? []) as $css) {
                $chunks[] = (string) ($css['sample'] ?? '');
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function summarizePages(array $pages): array
    {
        $summary = [];
        foreach ($pages as $type => $page) {
            $summary[$type] = [
                'url' => (string) ($page['url'] ?? ''),
                'title' => (string) ($page['title'] ?? ''),
                'description' => (string) ($page['description'] ?? ''),
                'headings' => array_slice((array) ($page['headings'] ?? []), 0, 8),
                'components' => (array) ($page['components'] ?? []),
                'text_sample' => mb_substr((string) ($page['text_sample'] ?? ''), 0, 500),
            ];
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function extractColors(string $cssText): array
    {
        preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\)/', $cssText, $matches);

        // CSS token files normally declare page backgrounds before their brand
        // colors.  Feeding the first literal to the generator therefore turns a
        // pale canvas color into the interactive primary color.  Put explicitly
        // named brand tokens first, while retaining the remaining source palette.
        preg_match_all(
            '/--[a-z0-9_-]*(?:primary|brand|accent)[a-z0-9_-]*\s*:\s*(#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\))/i',
            $cssText,
            $semanticMatches,
        );

        return array_slice($this->uniqueNormalized([
            ...($semanticMatches[1] ?? []),
            ...($matches[0] ?? []),
        ]), 0, 24);
    }

    /**
     * @return list<string>
     */
    private function extractFonts(string $cssText): array
    {
        preg_match_all(
            '/--[a-z0-9_-]*font-(?:sans|body|base)[a-z0-9_-]*\s*:\s*([^;}{]+)/i',
            $cssText,
            $preferredMatches,
        );
        preg_match_all(
            '/(?:font-family|--[a-z0-9_-]*font[a-z0-9_-]*)\s*:\s*([^;}{]+)/i',
            $cssText,
            $matches,
        );

        $fonts = [];
        foreach ([...($preferredMatches[1] ?? []), ...($matches[1] ?? [])] as $declaration) {
            foreach (preg_split('/\s*,\s*/', (string) $declaration) ?: [] as $font) {
                $font = trim(preg_replace('/\s+/', ' ', $font) ?? '', " \t\n\r\0\x0B\"'");
                if ($font === '' || str_starts_with(strtolower($font), 'var(')) {
                    continue;
                }

                $key = strtolower($font);
                $fonts[$key] ??= $font;
            }
        }

        return array_slice(array_values($fonts), 0, 6);
    }

    /**
     * @return list<string>
     */
    private function extractMostCommonCssValues(string $cssText, string $propertyPattern): array
    {
        preg_match_all('/(?:'.$propertyPattern.')\s*:\s*([^;}{]+)/i', $cssText, $matches);

        return array_slice($this->uniqueNormalized($matches[1] ?? []), 0, 8);
    }

    /**
     * @param  iterable<int, string>  $values
     * @return list<string>
     */
    private function uniqueNormalized(iterable $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/', ' ', strtolower((string) $value)) ?? '');
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function componentCount(array $pages, string $component): int
    {
        $count = 0;
        foreach ($pages as $page) {
            $count += (int) (($page['components'] ?? [])[$component] ?? 0);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function looksLikeSidebar(array $pages): bool
    {
        return $this->componentCount($pages, 'aside') > 0
            || str_contains(json_encode($pages, JSON_UNESCAPED_UNICODE) ?: '', 'sidebar');
    }
}
