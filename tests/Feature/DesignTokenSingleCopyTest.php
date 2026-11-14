<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 设计令牌「唯一副本」闸门。
 *
 * 背景（2026-09-02）：这批之前，44 个 --md-* 变量在 6 份页面样式里各抄了一份，
 * 已经开始分叉（同一个值出现 rgba(63,122,97,.18) 和 rgba(63, 122, 97, 0.18) 两种写法）。
 * 合并成 public/geo-tokens.css 之后，需要一道闸门挡住三件事：
 *
 *   1. 有人图省事，又把变量抄回某一页的 :root —— 分叉重新开始；
 *   2. 新加页面忘了引 geo-tokens.css，或者引在自己样式表后面 —— 页面覆盖不掉；
 *   3. 用了一个谁都没定义的变量 —— 浏览器静默当无效值，界面悄悄塌一块。
 *
 * 这三件事都不会让页面报错，只会让它变丑，靠肉眼走查抓不住，所以写成测试。
 */
class DesignTokenSingleCopyTest extends TestCase
{
    private const TOKENS_FILE = 'geo-tokens.css';

    /** 首页独有、故意不并进共享文件的变量（只有 welcome 用）。 */
    private const ENTRY_ONLY = ['--md-bg-page-warm', '--md-font-serif'];

    /** 2026-10-24 EXEC-09B 已清零，不许再加。 */
    private const KNOWN_UNDEFINED = [];

    /** 去掉 CSS 注释后再解析：带尾注释的声明会让按 ; 切割的解析漏读下一条。 */
    private function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
    }

    /** @return array<string,string> 变量名 => 值（只取 :root 块里的） */
    private function rootTokens(string $path): array
    {
        $css = $this->stripComments(file_get_contents($path));
        $out = [];
        preg_match_all('/:root\s*\{(.*?)\}/s', $css, $blocks);
        foreach ($blocks[1] as $body) {
            foreach (explode(';', $body) as $decl) {
                if (preg_match('/^\s*(--md-[a-z0-9-]+)\s*:\s*(.+)$/s', $decl, $m)) {
                    $out[$m[1]] = trim(preg_replace('/\s+/', ' ', $m[2]));
                }
            }
        }
        return $out;
    }

    /** @return string[] 该 blade 按 <link> 出现顺序引用的样式表文件名 */
    private function stylesheetChain(string $bladePath): array
    {
        preg_match_all(
            '#<link\s+rel="stylesheet"\s+href="/([a-z0-9.-]+\.css)#',
            file_get_contents($bladePath),
            $m
        );
        return $m[1];
    }

    /** @return array<string,string[]> blade 绝对路径 => 样式表链（只含真的引了样式表的页面） */
    private function pages(): array
    {
        $pages = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) continue;
            $blade = $file->getPathname();
            $chain = $this->stylesheetChain($blade);
            if ($chain !== []) {
                $pages[$blade] = $chain;
            }
        }
        $this->assertNotEmpty($pages, '一个引样式表的页面都没找到，八成是路径或正则写错了');
        return $pages;
    }

    public function test_每个设计令牌只被声明一次(): void
    {
        $owner = [];
        foreach (glob(public_path('geo-*.css')) as $file) {
            foreach (array_keys($this->rootTokens($file)) as $token) {
                $owner[$token][] = basename($file);
            }
        }

        $duplicated = array_filter($owner, fn ($files) => count($files) > 1);
        $this->assertSame([], $duplicated, sprintf(
            "这些变量被声明了不止一份，设计体系又开始分叉了：\n%s\n"
            . '共用的值只放 %s；某一页要不一样的，在那一页自己的样式表里覆盖。',
            json_encode($duplicated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            self::TOKENS_FILE
        ));

        // 除首页那两个独有变量外，其余一律归共享文件所有。
        foreach ($owner as $token => $files) {
            $expected = in_array($token, self::ENTRY_ONLY, true) ? 'geo-entry.css' : self::TOKENS_FILE;
            $this->assertSame([$expected], $files, "{$token} 应当声明在 {$expected} 里");
        }
    }

    public function test_共享令牌必须排在页面自身样式表前面(): void
    {
        foreach ($this->pages() as $blade => $chain) {
            $name = basename($blade);
            $position = array_search(self::TOKENS_FILE, $chain, true);

            $this->assertNotFalse(
                $position,
                "{$name} 没有引用 " . self::TOKENS_FILE . '，页面上所有 --md-* 都会取不到值'
            );
            $this->assertSame(0, $position, sprintf(
                '%s 里 %s 排在第 %d 位（顺序：%s）。它必须是第一个，'
                . '否则页面自己 :root 里的覆盖会被它压掉。',
                $name, self::TOKENS_FILE, $position + 1, implode(' → ', $chain)
            ));
        }
    }

    public function test_每个页面用到的令牌都解析得到值(): void
    {
        foreach ($this->pages() as $blade => $chain) {
            $declared = [];
            $used = [];
            foreach ($chain as $css) {
                $path = public_path($css);
                $this->assertFileExists($path, basename($blade) . " 引用了不存在的 {$css}");
                $declared += $this->rootTokens($path);   // 前面的先入为主即可，这里只判有无
                preg_match_all('/var\(\s*(--md-[a-z0-9-]+)/', $this->stripComments(file_get_contents($path)), $m);
                $used = array_merge($used, $m[1]);
            }

            $dangling = array_values(array_unique(array_diff($used, array_keys($declared), self::KNOWN_UNDEFINED)));
            sort($dangling);
            $this->assertSame([], $dangling, sprintf(
                "%s 用了没人定义的变量：%s\n浏览器会把这些声明当无效值丢掉，界面会悄悄塌一块。",
                basename($blade), implode(', ', $dangling)
            ));
        }
    }
}
