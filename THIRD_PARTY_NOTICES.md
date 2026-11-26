# 第三方许可与来源

本清单区分言及本身与第三方作品。根目录 Apache-2.0 不会替代依赖的 MIT、BSD、LGPL、OFL 等许可，也不表示全部代码由言及原创。版本以锁文件及实际构建镜像为准。

## 移植代码与技能

- GEOFlow：Copyright 2026 Yao Jingang，Apache-2.0。历史来源、修改范围见根目录 NOTICE，原始声明见 licenses/GEOFlow-NOTICE，文件映射见 licenses/ported-files.json。现有主题包建库历史保留原样；不因数据库文件未加新注释而遗漏其来源。
- yao-geo-skills：Copyright (c) 2026 Yao，MIT，锁定提交见 resources/skills/upstream-lock.json。镜像构建校验全部锁定文件，保留 /app/yao-geo-skills/LICENSE；本仓修改版和示例适用 skill-overlays/LICENSE，逐文件原始声明继续保留。
- Laravel 脚手架与 Laravel 框架：Taylor Otwell 及贡献者，MIT。框架完整许可随 vendor/laravel/framework/LICENSE.md 保留；项目元数据不再沿用脚手架的项目名称。

## 容器中的完整声明

构建时 scripts/collect-licenses.py 从已安装软件包复制许可、版权和 NOTICE 原文，不改写作者署名：

- /app/licenses/runtime/inventory.json：实际 Composer、Python 和 Alpine 包版本、来源与许可；文本位于同目录 composer/、python/、system-licenses/ 等子目录。
- /app/licenses/npm/inventory.json：当前架构实际安装的 npm 包及许可文本。其他平台的可选包仍列在源码锁文件清单中。
- 原始 vendor 许可、Python dist-info 许可、字体 OFL 与技能 LICENSE 仍随各组件保留。

PHPWord 和 PDFParser 使用 LGPL 系列。分发或修改这些库时须遵守其各自许可证，包括保留许可、提供适用的库源码及修改、允许按许可替换或重新链接。它们以独立依赖形式安装，未改写其源码；源码仓库与精确版本在 composer.lock 的 source 字段。系统组件包含 GPL/LGPL 软件，不能将整枚容器描述为“全部 Apache/MIT”。

Alpine 的包源、版本、origin 与 aports 提交在运行时清单中，可据此在 [Alpine aports](https://gitlab.alpinelinux.org/alpine/aports) 查对应 APKBUILD 与源码位置；基础镜像的来源和不可变摘要保留在 Dockerfile。再次分发容器的维护者需随所分发版本履行各组件的源码和声明义务。

## 字体与品牌资产

- 网页 Noto Sans SC 与 Roboto Mono：SIL Open Font License 1.1。原文位于 public/fonts/notosanssc/OFL.txt、public/fonts/robotomono/OFL.txt；来源、版本和校验值见 public/fonts/manifest.json。字体经过子集化，沿用原作者声明。
- 报告 Noto CJK：OFL-1.1；DejaVu：Bitstream Vera / DejaVu 许可。通过 Alpine 字体包安装，实际包信息列在容器清单中；字体许可不由项目的 Apache-2.0 替代。构建从 licenses/font-sources.json 的固定提交下载并校验原始声明，保存在 /app/licenses/report-fonts/。
- 言及品牌资源见 public/brand/yanji/。代码许可不授予品牌、商标或“官方”身份使用权，见 README 的品牌说明。

## Composer 锁定依赖

包括开发测试依赖；生产镜像仅安装运行依赖。完整上游源地址和引用见 composer.lock，许可证文本随安装包保留。

| 包 | 版本 | 许可 | 范围 |
| --- | --- | --- | --- |
| brick/math | 0.18.0 | MIT | 运行 |
| carbonphp/carbon-doctrine-types | 3.2.0 | MIT | 运行 |
| clue/redis-protocol | v0.3.2 | MIT | 运行 |
| clue/redis-react | v2.8.0 | MIT | 运行 |
| dflydev/dot-access-data | v3.0.3 | MIT | 运行 |
| doctrine/inflector | 2.1.0 | MIT | 运行 |
| doctrine/lexer | 3.0.1 | MIT | 运行 |
| dragonmantank/cron-expression | v3.6.0 | MIT | 运行 |
| egulias/email-validator | 4.0.4 | MIT | 运行 |
| evenement/evenement | v3.0.2 | MIT | 运行 |
| fruitcake/php-cors | v1.4.0 | MIT | 运行 |
| graham-campbell/result-type | v1.1.4 | MIT | 运行 |
| guzzlehttp/guzzle | 7.15.5 | MIT | 运行 |
| guzzlehttp/promises | 2.5.3 | MIT | 运行 |
| guzzlehttp/psr7 | 2.13.1 | MIT | 运行 |
| guzzlehttp/uri-template | v1.0.8 | MIT | 运行 |
| laravel/framework | v13.40.0 | MIT | 运行 |
| laravel/prompts | v0.3.27 | MIT | 运行 |
| laravel/reverb | v1.10.2 | MIT | 运行 |
| laravel/sanctum | v4.3.2 | MIT | 运行 |
| laravel/serializable-closure | v2.0.13 | MIT | 运行 |
| laravel/tinker | v3.0.2 | MIT | 运行 |
| league/commonmark | 2.10.3 | BSD-3-Clause | 运行 |
| league/config | v1.2.0 | BSD-3-Clause | 运行 |
| league/flysystem | 3.36.0 | MIT | 运行 |
| league/flysystem-local | 3.31.0 | MIT | 运行 |
| league/mime-type-detection | 1.16.0 | MIT | 运行 |
| league/uri | 7.8.1 | MIT | 运行 |
| league/uri-interfaces | 7.8.1 | MIT | 运行 |
| monolog/monolog | 3.10.0 | MIT | 运行 |
| nesbot/carbon | 3.13.0 | MIT | 运行 |
| nette/schema | v1.3.5 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | 运行 |
| nette/utils | v4.1.4 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | 运行 |
| nikic/php-parser | v5.7.0 | BSD-3-Clause | 运行 |
| nunomaduro/termwind | v2.4.0 | MIT | 运行 |
| phpoffice/math | 0.3.0 | MIT | 运行 |
| phpoffice/phpword | 1.4.0 | LGPL-3.0-only | 运行 |
| phpoption/phpoption | 1.9.5 | Apache-2.0 | 运行 |
| psr/clock | 1.0.0 | MIT | 运行 |
| psr/container | 2.0.2 | MIT | 运行 |
| psr/event-dispatcher | 1.0.0 | MIT | 运行 |
| psr/http-client | 1.0.3 | MIT | 运行 |
| psr/http-factory | 1.1.0 | MIT | 运行 |
| psr/http-message | 2.0 | MIT | 运行 |
| psr/log | 3.0.2 | MIT | 运行 |
| psr/simple-cache | 3.0.0 | MIT | 运行 |
| psy/psysh | v0.12.23 | MIT | 运行 |
| pusher/pusher-php-server | 7.2.8 | MIT | 运行 |
| ralouphie/getallheaders | 3.0.3 | MIT | 运行 |
| ramsey/collection | 2.1.1 | MIT | 运行 |
| ramsey/uuid | 4.9.3 | MIT | 运行 |
| ratchet/rfc6455 | v0.4.1 | MIT | 运行 |
| react/cache | v1.2.0 | MIT | 运行 |
| react/dns | v1.14.0 | MIT | 运行 |
| react/event-loop | v1.6.0 | MIT | 运行 |
| react/promise | v3.3.0 | MIT | 运行 |
| react/promise-timer | v1.11.0 | MIT | 运行 |
| react/socket | v1.17.0 | MIT | 运行 |
| react/stream | v1.4.0 | MIT | 运行 |
| smalot/pdfparser | v2.12.5 | LGPL-3.0 | 运行 |
| symfony/clock | v8.1.0 | MIT | 运行 |
| symfony/console | v8.1.0 | MIT | 运行 |
| symfony/css-selector | v8.1.0 | MIT | 运行 |
| symfony/deprecation-contracts | v3.7.0 | MIT | 运行 |
| symfony/error-handler | v8.1.0 | MIT | 运行 |
| symfony/event-dispatcher | v8.1.0 | MIT | 运行 |
| symfony/event-dispatcher-contracts | v3.7.0 | MIT | 运行 |
| symfony/finder | v8.1.0 | MIT | 运行 |
| symfony/http-foundation | v8.1.12 | MIT | 运行 |
| symfony/http-kernel | v8.1.12 | MIT | 运行 |
| symfony/mailer | v8.1.12 | MIT | 运行 |
| symfony/mime | v8.1.10 | MIT | 运行 |
| symfony/polyfill-ctype | v1.37.0 | MIT | 运行 |
| symfony/polyfill-intl-grapheme | v1.38.1 | MIT | 运行 |
| symfony/polyfill-intl-idn | v1.38.1 | MIT | 运行 |
| symfony/polyfill-intl-normalizer | v1.38.0 | MIT | 运行 |
| symfony/polyfill-mbstring | v1.38.2 | MIT | 运行 |
| symfony/polyfill-php80 | v1.37.0 | MIT | 运行 |
| symfony/polyfill-php84 | v1.38.1 | MIT | 运行 |
| symfony/polyfill-php85 | v1.38.1 | MIT | 运行 |
| symfony/polyfill-php86 | v1.38.0 | MIT | 运行 |
| symfony/polyfill-uuid | v1.37.0 | MIT | 运行 |
| symfony/process | v8.1.0 | MIT | 运行 |
| symfony/routing | v8.1.12 | MIT | 运行 |
| symfony/service-contracts | v3.7.0 | MIT | 运行 |
| symfony/string | v8.1.0 | MIT | 运行 |
| symfony/translation | v8.1.0 | MIT | 运行 |
| symfony/translation-contracts | v3.7.0 | MIT | 运行 |
| symfony/uid | v8.1.0 | MIT | 运行 |
| symfony/var-dumper | v8.1.0 | MIT | 运行 |
| tijsverkoyen/css-to-inline-styles | v2.4.0 | BSD-3-Clause | 运行 |
| vlucas/phpdotenv | v5.6.3 | BSD-3-Clause | 运行 |
| voku/portable-ascii | 2.1.1 | MIT | 运行 |
| fakerphp/faker | v1.24.1 | MIT | 开发/测试 |
| filp/whoops | 2.18.4 | MIT | 开发/测试 |
| hamcrest/hamcrest-php | v2.1.1 | BSD-3-Clause | 开发/测试 |
| laravel/agent-detector | v2.0.2 | MIT | 开发/测试 |
| laravel/pail | v1.2.7 | MIT | 开发/测试 |
| laravel/pao | v1.1.2 | MIT | 开发/测试 |
| laravel/pint | v1.29.3 | MIT | 开发/测试 |
| mockery/mockery | 1.6.12 | BSD-3-Clause | 开发/测试 |
| myclabs/deep-copy | 1.13.4 | MIT | 开发/测试 |
| nunomaduro/collision | v8.9.4 | MIT | 开发/测试 |
| phar-io/manifest | 2.0.4 | BSD-3-Clause | 开发/测试 |
| phar-io/version | 3.2.1 | BSD-3-Clause | 开发/测试 |
| phpunit/php-code-coverage | 12.5.7 | BSD-3-Clause | 开发/测试 |
| phpunit/php-file-iterator | 6.0.1 | BSD-3-Clause | 开发/测试 |
| phpunit/php-invoker | 6.0.0 | BSD-3-Clause | 开发/测试 |
| phpunit/php-text-template | 5.0.0 | BSD-3-Clause | 开发/测试 |
| phpunit/php-timer | 8.0.0 | BSD-3-Clause | 开发/测试 |
| phpunit/phpunit | 12.5.30 | BSD-3-Clause | 开发/测试 |
| sebastian/cli-parser | 4.2.1 | BSD-3-Clause | 开发/测试 |
| sebastian/comparator | 7.1.8 | BSD-3-Clause | 开发/测试 |
| sebastian/complexity | 5.0.0 | BSD-3-Clause | 开发/测试 |
| sebastian/diff | 7.0.0 | BSD-3-Clause | 开发/测试 |
| sebastian/environment | 8.1.2 | BSD-3-Clause | 开发/测试 |
| sebastian/exporter | 7.0.3 | BSD-3-Clause | 开发/测试 |
| sebastian/global-state | 8.0.3 | BSD-3-Clause | 开发/测试 |
| sebastian/lines-of-code | 4.0.1 | BSD-3-Clause | 开发/测试 |
| sebastian/object-enumerator | 7.0.0 | BSD-3-Clause | 开发/测试 |
| sebastian/object-reflector | 5.0.0 | BSD-3-Clause | 开发/测试 |
| sebastian/recursion-context | 7.0.1 | BSD-3-Clause | 开发/测试 |
| sebastian/type | 6.0.4 | BSD-3-Clause | 开发/测试 |
| sebastian/version | 6.0.0 | BSD-3-Clause | 开发/测试 |
| staabm/side-effects-detector | 1.0.5 | MIT | 开发/测试 |
| theseer/tokenizer | 2.0.1 | BSD-3-Clause | 开发/测试 |

## npm 锁定依赖

本项目 npm 依赖用于前端构建和开发；生产镜像不运行 Vite 开发服务器。表中包含其他架构的可选包，实际安装集合见容器清单。

| 包路径 | 版本 | 许可 |
| --- | --- | --- |
| @emnapi/core | 2.0.0-beta.2 | MIT |
| @emnapi/runtime | 2.0.0-beta.2 | MIT |
| @emnapi/wasi-threads | 2.2.0 | MIT |
| @jridgewell/gen-mapping | 0.3.13 | MIT |
| @jridgewell/remapping | 2.3.5 | MIT |
| @jridgewell/resolve-uri | 3.1.2 | MIT |
| @jridgewell/sourcemap-codec | 1.5.5 | MIT |
| @jridgewell/trace-mapping | 0.3.31 | MIT |
| @napi-rs/wasm-runtime | 1.2.6 | MIT |
| @oxc-project/types | 0.156.0 | MIT |
| @rolldown/binding-android-arm-eabi | 1.2.19 | MIT |
| @rolldown/binding-android-arm64 | 1.2.19 | MIT |
| @rolldown/binding-darwin-arm64 | 1.2.19 | MIT |
| @rolldown/binding-darwin-x64 | 1.2.19 | MIT |
| @rolldown/binding-freebsd-x64 | 1.2.19 | MIT |
| @rolldown/binding-linux-arm-gnueabihf | 1.2.19 | MIT |
| @rolldown/binding-linux-arm64-gnu | 1.2.19 | MIT |
| @rolldown/binding-linux-arm64-musl | 1.2.19 | MIT |
| @rolldown/binding-linux-ppc64-gnu | 1.2.19 | MIT |
| @rolldown/binding-linux-s390x-gnu | 1.2.19 | MIT |
| @rolldown/binding-linux-x64-gnu | 1.2.19 | MIT |
| @rolldown/binding-linux-x64-musl | 1.2.19 | MIT |
| @rolldown/binding-openharmony-arm64 | 1.2.19 | MIT |
| @rolldown/binding-wasm32-wasi | 1.2.19 | MIT |
| @rolldown/binding-win32-arm64-msvc | 1.2.19 | MIT |
| @rolldown/binding-win32-x64-msvc | 1.2.19 | MIT |
| @rolldown/pluginutils | 1.1.0 | MIT |
| @tailwindcss/node | 4.3.2 | MIT |
| @tailwindcss/oxide | 4.3.2 | MIT |
| @tailwindcss/oxide-android-arm64 | 4.3.2 | MIT |
| @tailwindcss/oxide-darwin-arm64 | 4.3.2 | MIT |
| @tailwindcss/oxide-darwin-x64 | 4.3.2 | MIT |
| @tailwindcss/oxide-freebsd-x64 | 4.3.2 | MIT |
| @tailwindcss/oxide-linux-arm-gnueabihf | 4.3.2 | MIT |
| @tailwindcss/oxide-linux-arm64-gnu | 4.3.2 | MIT |
| @tailwindcss/oxide-linux-arm64-musl | 4.3.2 | MIT |
| @tailwindcss/oxide-linux-x64-gnu | 4.3.2 | MIT |
| @tailwindcss/oxide-linux-x64-musl | 4.3.2 | MIT |
| @tailwindcss/oxide-wasm32-wasi | 4.3.2 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/@emnapi/core | 1.11.1 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/@emnapi/runtime | 1.11.1 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/@emnapi/wasi-threads | 1.2.2 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/@napi-rs/wasm-runtime | 1.1.4 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/@tybys/wasm-util | 0.10.2 | MIT |
| @tailwindcss/oxide-wasm32-wasi/node_modules/tslib | 2.8.1 | 0BSD |
| @tailwindcss/oxide-win32-arm64-msvc | 4.3.2 | MIT |
| @tailwindcss/oxide-win32-x64-msvc | 4.3.2 | MIT |
| @tailwindcss/vite | 4.3.2 | MIT |
| @tybys/wasm-util | 0.10.4 | MIT |
| ansi-regex | 5.0.1 | MIT |
| ansi-styles | 4.3.0 | MIT |
| chalk | 4.1.2 | MIT |
| chalk/node_modules/supports-color | 7.2.0 | MIT |
| cliui | 8.0.1 | ISC |
| color-convert | 2.0.1 | MIT |
| color-name | 1.1.4 | MIT |
| concurrently | 9.2.5 | MIT |
| detect-libc | 2.1.2 | Apache-2.0 |
| emoji-regex | 8.0.0 | MIT |
| enhanced-resolve | 5.21.6 | MIT |
| escalade | 3.2.0 | MIT |
| fdir | 6.5.0 | MIT |
| fsevents | 2.3.3 | MIT |
| get-caller-file | 2.0.5 | ISC |
| graceful-fs | 4.2.11 | ISC |
| has-flag | 4.0.0 | MIT |
| is-fullwidth-code-point | 3.0.0 | MIT |
| jiti | 2.7.0 | MIT |
| laravel-vite-plugin | 3.1.0 | MIT |
| lightningcss | 1.32.0 | MPL-2.0 |
| lightningcss-android-arm64 | 1.32.0 | MPL-2.0 |
| lightningcss-darwin-arm64 | 1.32.0 | MPL-2.0 |
| lightningcss-darwin-x64 | 1.32.0 | MPL-2.0 |
| lightningcss-freebsd-x64 | 1.32.0 | MPL-2.0 |
| lightningcss-linux-arm-gnueabihf | 1.32.0 | MPL-2.0 |
| lightningcss-linux-arm64-gnu | 1.32.0 | MPL-2.0 |
| lightningcss-linux-arm64-musl | 1.32.0 | MPL-2.0 |
| lightningcss-linux-x64-gnu | 1.32.0 | MPL-2.0 |
| lightningcss-linux-x64-musl | 1.32.0 | MPL-2.0 |
| lightningcss-win32-arm64-msvc | 1.32.0 | MPL-2.0 |
| lightningcss-win32-x64-msvc | 1.32.0 | MPL-2.0 |
| magic-string | 0.30.21 | MIT |
| nanoid | 3.3.20 | MIT |
| picocolors | 1.1.1 | ISC |
| picomatch | 4.0.7 | MIT |
| postcss | 8.5.32 | MIT |
| require-directory | 2.1.1 | MIT |
| rolldown | 1.2.19 | MIT |
| rxjs | 7.8.2 | Apache-2.0 |
| shell-quote | 1.12.0 | MIT |
| source-map-js | 1.2.2 | BSD-3-Clause |
| string-width | 4.2.3 | MIT |
| strip-ansi | 6.0.1 | MIT |
| supports-color | 8.1.1 | MIT |
| tailwindcss | 4.3.2 | MIT |
| tapable | 2.3.3 | MIT |
| tinyglobby | 0.2.17 | MIT |
| tree-kill | 1.2.2 | MIT |
| tslib | 2.8.1 | 0BSD |
| vite | 8.3.8 | MIT |
| vite-plugin-full-reload | 1.2.0 | MIT |
| vite-plugin-full-reload/node_modules/picomatch | 2.3.2 | MIT |
| vite/node_modules/lightningcss | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-android-arm64 | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-darwin-arm64 | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-darwin-x64 | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-freebsd-x64 | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-linux-arm-gnueabihf | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-linux-arm64-gnu | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-linux-arm64-musl | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-linux-x64-gnu | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-linux-x64-musl | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-win32-arm64-msvc | 1.33.0 | MPL-2.0 |
| vite/node_modules/lightningcss-win32-x64-msvc | 1.33.0 | MPL-2.0 |
| wrap-ansi | 7.0.0 | MIT |
| y18n | 5.0.8 | ISC |
| yargs | 17.7.2 | MIT |
| yargs-parser | 21.1.1 | ISC |

## Python 实际安装依赖

以下来自 2026-11-13 的 Linux arm64 构建；直接依赖固定在 Dockerfile，传递依赖可能随重新构建变化，镜像内清单为该镜像的准确记录。

| 包 | 版本 | 许可 |
| --- | --- | --- |
| beautifulsoup4 | 4.15.0 | MIT License |
| brotli | 1.2.1 | MIT |
| cffi | 2.1.1 | MIT-0 |
| charset-normalizer | 3.5.2 | MIT |
| cssselect2 | 0.10.1 | BSD License |
| fonttools | 4.68.0 | MIT |
| lxml | 6.1.3 | BSD-3-Clause |
| Markdown | 3.10.3 | BSD-3-Clause |
| pillow | 13.0.0 | MIT-CMU |
| pip | 26.2 | MIT |
| pycparser | 3.12 | BSD-3-Clause |
| pydyf | 0.13.0 | BSD License |
| pypdf | 7.0.1 | BSD-3-Clause |
| pyphen | 0.18.1 | GNU General Public License v2 or later (GPLv2+) / GNU Lesser General Public License v2 or later (LGPLv2+) / Mozilla Public License 1.1 (MPL 1.1) |
| python-docx | 1.2.0 | MIT |
| reportlab | 5.0.0 | BSD license (see license.txt for details), Copyright (c) 2000-2025, ReportLab Inc. |
| soupsieve | 4.1 | MIT |
| tinycss2 | 1.5.1 | BSD License |
| tinyhtml5 | 2.1.0 | MIT License |
| typing_extensions | 4.16.0 | PSF-2.0 |
| weasyprint | 70.0 | BSD License |
| webencodings | 0.6.1 | BSD License |
| zopfli | 0.4.3 | Apache-2.0 |

## 上游许可文本缺口

laravel/sentinel v1.1.0 与 pusher/pusher-php-server 7.2.8 在 composer.json 声明 MIT，但锁定源码和分发包没有独立许可原文。镜像保留其原始 composer.json 与精确来源，不伪造作者声明；详见 licenses/supplemental-sources.json。此缺口应在公开发行前复核，不能把许可收集完成理解为已经获得所有第三方的额外授权。

## 系统软件

容器使用 FrankenPHP/Caddy、PHP、Alpine、PostgreSQL 客户端库、Poppler、Cairo、Pango、GDK Pixbuf、libffi、图像和字体库。FrankenPHP/Caddy 使用 Apache-2.0，PHP 使用 PHP License，PostgreSQL 使用 PostgreSQL License；其余系统库包含 GPL、LGPL、MIT、BSD、OFL 等许可。完整包级清单由每次构建生成，不能用本段概述替代它。数据库服务使用单独的 PostgreSQL/pgvector 镜像，其版本摘要在 docker-compose.yml；相关许可随该镜像分发。
