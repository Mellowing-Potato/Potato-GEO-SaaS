# ---------------------------------------------------------------------------
# 自部署镜像：固定基础镜像摘要，依赖使用仓库锁文件。
#   默认值全部指向官方源 —— 任何联网（含墙外）环境 `docker build .` 即可复现。
#   出网受限的构建机（如生产服务器：Docker Hub / Alpine CDN 不通）通过
#   --build-arg 切换依赖源。
#
#   可复现性加固（2026-07-22，评审人）：
#   - base 镜像按 digest 锁定（tag 可变，digest 不可变）；国内镜像源拉到的同 digest
#     base 与官方 byte 级同一枚（生产 RepoDigests 双重佐证），换源不改 base 身份。
#   - 升级基础镜像时同步更新这里的 digest，并重新验证两个架构。
# ---------------------------------------------------------------------------
ARG COMPOSER_IMAGE=composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c
ARG NODE_IMAGE=node:22-alpine@sha256:16e22a550f3863206a3f701448c45f7912c6896a62de43add43bb9c86130c3e2
# FrankenPHP base（2026-08-20 换 Web 服务器批，见 docs/GEO-Web服务器换FrankenPHP-工兵执行书）
#   digest 锁定口径与 composer/node 一致：tag 可变，digest 不可变。
#   构建会校验锁定的 manifest digest。
#   ⚠️ 这枚 digest 是任务书 2026-08-20 实拉 dunglas/frankenphp:1-php8.4-alpine 得到的
#   manifest list digest（PHP 8.4.24 ZTS / FrankenPHP 1.12.7 / Caddy 2.11.4）。
#   开工时请自己重拉一次核对（docker buildx 拉的是 digest 钉死的这枚，拉不到即失败，等于自带校验）。
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4-alpine@sha256:21b7f6ff9953e4b351bd5d3b04fbbee9868df568a13843c99e0274b3c3108f44
# 空 = 保留镜像自带 Alpine CDN；给值(如 https://mirrors.aliyun.com/alpine)则替换 apk 源
ARG APK_MIRROR=
# pip 索引源（默认官方 PyPI）
ARG PIP_INDEX_URL=https://pypi.org/simple
# 空 = 默认 packagist；给值(如 https://mirrors.aliyun.com/composer)则覆盖 composer 源
ARG COMPOSER_REPO=
# npm registry（默认官方）
ARG NPM_REGISTRY=https://registry.npmjs.org

# 只为取 composer 二进制：FrankenPHP 镜像里没有 composer（任务书 §0.1 实测
# command -v composer 无结果），而 php-dependencies 阶段要跑 composer install。
# 单独开一枚 stage 从 composer base 拷二进制，别去 apk add composer（版本对不上）。
FROM ${COMPOSER_IMAGE} AS composer-src

FROM ${NODE_IMAGE} AS skill-assets
RUN apk add --no-cache python3
WORKDIR /build
COPY scripts/fetch-skills.py ./fetch-skills.py
COPY resources/skills/upstream-lock.json ./upstream-lock.json
RUN python3 fetch-skills.py upstream-lock.json /skills
COPY scripts/fetch-font-licenses.py ./fetch-font-licenses.py
COPY licenses/font-sources.json ./font-sources.json
RUN python3 fetch-font-licenses.py font-sources.json /font-licenses

FROM ${FRANKENPHP_IMAGE} AS php-runtime-base

# FROM 之后需重新声明所需 ARG 才能在本 stage 使用
ARG APK_MIRROR
ARG PIP_INDEX_URL

# 仅当显式指定 APK_MIRROR 时替换 Alpine 源（出网受限构建机用）
RUN if [ -n "${APK_MIRROR}" ]; then \
        sed -i "s#https://dl-cdn.alpinelinux.org/alpine#${APK_MIRROR}#g" /etc/apk/repositories; \
    fi

# 技能渲染脚本的 Python 依赖（2026-08-06 补齐）
#   此前这里只装了 python-docx，于是 yao-geo-skills 里四个技能的渲染脚本
#   在 import 阶段就崩：
#     yao-geo-execution-roadmap      markdown / weasyprint
#     yao-geo-page-blueprint         weasyprint
#     yao-geo-ranking-article-builder markdown / bs4 / weasyprint
#     yao-geo-content-refiner        reportlab
#   这四个技能的 render_success 在生产上恒为 false，客户只能拿到原始输出。
#
#   font-noto-cjk 不是可选项：实测不装它 weasyprint 出的中文 PDF 只有 4.8KB、
#   正文全是豆腐块且无法提取文字；装上后 199KB、中文正常且可提取。
#   cairo / pango / gdk-pixbuf / libffi 是 weasyprint 的运行时依赖。
RUN apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        python3 \
        py3-pip \
        postgresql-libs \
        cairo \
        pango \
        gdk-pixbuf \
        libffi \
        ttf-dejavu \
        font-noto-cjk \
        libzip \
        poppler-utils \
    && apk add --no-cache --virtual .php-build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        postgresql-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    # zip 是 phpoffice/phpword 的硬依赖；旧 composer base 自带 ext-zip，FrankenPHP base 没有，
    # 换 base 后 composer install 在 lock 校验阶段即报 missing ext-zip。libzip-dev 见上方 .php-build-deps。
    && docker-php-ext-install -j"$(nproc)" gd pcntl pdo_pgsql pgsql zip \
    && apk del .php-build-deps \
    && python3 -m pip install --break-system-packages --no-cache-dir -i "${PIP_INDEX_URL}" \
        'python-docx==1.2.0' \
        'Markdown==3.10.3' \
        'beautifulsoup4==4.15.0' \
        'reportlab==5.0.0' \
        'weasyprint==70.0' \
        'pypdf==7.0.1' \
        'pip==26.2' \
    && python3 -c "import docx, markdown, bs4, reportlab, weasyprint, pypdf" \
    # poppler-utils（B1，2026-08-21 知识库多模态批）：pdftoppm 渲染页图 / pdfinfo 页数尺寸 /
    # pdfimages 图覆盖判定 / pdftotext 单页字符数。与上面同一层 apk add，不新起 RUN。
    # 注意 `pdftoppm -v` 退出码是 99，必须接管道让退出码取 head 的 0，否则 build 直接挂。
    && pdftoppm -v 2>&1 | head -1

# 报告下载会内联三种字体，PDF 图片识别需要完整 Poppler 命令组。
# 构建时检查实际脚本引用的文件与命令，避免部署后才让付费任务落入编辑稿。
RUN for font in \
        /usr/share/fonts/noto/NotoSerifCJK-Regular.ttc \
        /usr/share/fonts/dejavu/DejaVuSans.ttf \
        /usr/share/fonts/dejavu/DejaVuSans-Bold.ttf; do \
        test -s "$font" || { echo "Missing report font: $font" >&2; exit 1; }; \
    done \
    && for executable in pyftsubset pdfinfo pdfimages pdftotext pdftoppm; do \
        command -v "$executable" >/dev/null || { echo "Missing report/PDF tool: $executable" >&2; exit 1; }; \
    done

# 文档上传限制（2026-08-07 由 5M 提到 10M，老板拍板）
#   upload_max_filesize=10M 为单文件硬上限。php.ini 无 upload 段，走 conf.d drop-in。
#
#   为什么从 5M 提上来：老板传一份 7.8MB 的课程手册被拒，而带图的 PDF 超过 5M
#   太常见了。更糟的是提示——PHP 在接收阶段就拒了，Laravel 只能报一句英文的
#   「The file failed to upload.」，完全看不出是体积问题。
#   （提示已在 KnowledgeBaseController::uploadSizeError() 一并修掉。）
#
#   ⚠️ 改这里必须同步改 KnowledgeBaseController::MAX_UPLOAD_KB，只改一边不生效：
#   PHP 这层拦得更早，应用层那条 max: 规则根本没机会跑。
#
#   memory_limit=512M 是同步处理模式下的配套上限。注意**解析内存与文件大小不成正比**：
#   实测 1.22MB/17页的 PDF 峰值 4MB，1.28MB/9页的却要 24.3MB，7.8MB 那份是 36.4MB。
#   所以这个上限挡不住结构特殊的 PDF，真正的根治是把入库挪进队列（未做）。
#   ⚠️ post_max_size 与 upload_max_filesize 的关系（2026-08-11 踩过，别再调回去）：
#   前者是**整个表单**的上限（信封），后者是**单个文件**的上限（信纸）。
#   信封必须明显大于信纸，否则超限的文件会先撞信封那条线，而撞信封的后果要糟得多：
#   PHP 在 Laravel 启动**之前**就把 $_POST / $_FILES 全部清空，应用层那句写清楚
#   大小的中文提示根本没机会跑，只能退化成一句笼统的 413。
#   信封留 20M，是为了让 10M~20M 之间的文件走应用层，拿到「文件约 18.1 MB，超过
#   10 MB 上限」这种能看懂的提示。**单文件上限仍然是 10M，没有放宽。**
RUN echo "upload_max_filesize=10M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=20M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini

# 生产绝不把 PHP 报错打进响应体（2026-08-11 修）
#   composer 基础镜像默认 display_errors=STDOUT。后果远不止难看：PHP 在
#   Request Startup 阶段产生的警告会**先于 Laravel** 输出，响应头随之以默认的
#   200 + text/html 发出去，之后 Laravel 再想返回 413/422 已经改不动状态码了。
#   实测：传 19MB 文件 → HTTP 200 + 一句 PHP 警告（没有 JSON），前端据此判定
#   「上传成功」，而文件根本没入库。关掉之后同一请求正确返回 413。
#   附带好处：不再把容器内路径、行号这类内部信息回显给客户。
RUN echo "display_errors=Off" > /usr/local/etc/php/conf.d/errors.ini \
    && echo "log_errors=On" >> /usr/local/etc/php/conf.d/errors.ini \
    && echo "error_log=/dev/stderr" >> /usr/local/etc/php/conf.d/errors.ini

# Caddy 开机要建本地 CA，会写 /data/caddy；容器以 www-data 跑，不 chown 直接 Exited(1)：
#   mkdir /data/caddy/pki: permission denied（任务书 §0.2 实测）。必须在 USER www-data 之前。
RUN mkdir -p /data/caddy /config/caddy && chown -R www-data:www-data /data/caddy /config/caddy

FROM php-runtime-base AS php-dependencies

ARG COMPOSER_REPO

# composer 默认 12 路并发下 TLS 到 codeload.github.com；出网抖动的构建机上
# 这 12 条里总有一两条被重置，composer 随即回落 source 安装、而镜像里没有 git，
# 于是整个 build 挂在一句莫名其妙的 "git was not found in your PATH"。
# 实测同一条 zipball 顺序拉 8/8 成功、并发就挂 —— 是并发不是可达性。
# 调小它即可（1 最稳，慢几十秒）。不设＝composer 自己的默认值，产物逐字节不变。
ARG COMPOSER_MAX_PARALLEL_HTTP=12
ENV COMPOSER_MAX_PARALLEL_HTTP=${COMPOSER_MAX_PARALLEL_HTTP}

WORKDIR /app

# FrankenPHP base 没有 composer，从 composer-src stage 拷二进制（任务书 §0.1 / T4）
COPY --from=composer-src /usr/bin/composer /usr/bin/composer

# 仅当显式指定 COMPOSER_REPO 时覆盖 packagist 源（出网受限构建机用）
RUN if [ -n "${COMPOSER_REPO}" ]; then \
        composer config -g repos.packagist composer "${COMPOSER_REPO}"; \
    fi

COPY . .

# 下载失败重试，且把已下好的包留在 BuildKit 缓存里。
#
# 2026-09-07 补：上面那条注释说「是并发不是可达性」，那天是。这天不是 ——
# 构建机到 codeload.github.com 的成功率掉到约 40%，串行（并发=1）同样每包必挂几个。
# 96 个包要求一次全中，40% 成功率下等于永远构建不出来。
#
# 两件事一起做：
#   ① --mount=type=cache 把 composer 的包缓存留在宿主机 BuildKit 里，
#      于是每次重试只需要补上一次没下到的那些，成功率是累乘不是重来；
#   ② 单条 RUN 内自己重试 8 次，一次构建就能收敛，不用人肉重跑八遍。
#
# ⚠️ 缓存挂载只影响「zip 从哪来」，不影响装出来的内容：composer 按 lock 里的
#    reference 校验每个包，命中缓存与新下载的产物逐字节相同。
# ⚠️ 重试只对「下载失败」有意义。依赖冲突、平台不满足这类错误重试 8 次也还是错，
#    只是多花几十秒；失败仍然会以非零码退出，不会把坏构建放过去。
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    export COMPOSER_CACHE_DIR=/tmp/composer-cache; \
    for attempt in 1 2 3 4 5 6 7 8; do \
        echo "[composer] 第 ${attempt} 次尝试"; \
        composer install \
            --no-dev \
            --no-interaction \
            --no-progress \
            --prefer-dist \
            --optimize-autoloader \
        && exit 0; \
        echo "[composer] 第 ${attempt} 次失败，已下好的包留在缓存里，5 秒后重试"; \
        sleep 5; \
    done; \
    echo "[composer] 8 次全失败，放弃"; \
    exit 1

FROM ${NODE_IMAGE} AS frontend-assets

ARG NPM_REGISTRY

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --registry "${NPM_REGISTRY}"

COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# Preserve licenses of installed frontend/build dependencies in the final image.
RUN apk add --no-cache python3
COPY scripts/collect-licenses.py /tmp/collect-licenses.py
RUN python3 /tmp/collect-licenses.py npm /app /npm-licenses

# Opt-in Linux test target. Tests are a separate BuildKit context so the default
# production context continues to exclude tests and private upstream skill files.
# Build with scripts/test-linux.py (or --build-context test-sources=./tests).
# Keep runtime LAST: an ordinary docker build still produces the production image.
FROM php-runtime-base AS test

ARG COMPOSER_REPO
ARG COMPOSER_MAX_PARALLEL_HTTP=12
ENV COMPOSER_MAX_PARALLEL_HTTP=${COMPOSER_MAX_PARALLEL_HTTP}
WORKDIR /app
COPY --from=composer-src /usr/bin/composer /usr/bin/composer
COPY --from=frontend-assets /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend-assets /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
# git 只给测试镜像：发布源护栏的 JS 测试要临时建 git 仓库验证。
RUN apk add --no-cache libstdc++ git \
    && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && if [ -n "${COMPOSER_REPO}" ]; then composer config -g repos.packagist composer "${COMPOSER_REPO}"; fi
COPY --chown=www-data:www-data . .
COPY --from=test-sources --chown=www-data:www-data / /app/tests/
COPY --from=frontend-assets /app/public/build ./public/build
# 测试镜像带上 npm ci 锁定的 node_modules：排版测试直接用 postcss（vite 的依赖）。
COPY --from=frontend-assets /app/node_modules ./node_modules
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    export COMPOSER_CACHE_DIR=/tmp/composer-cache; \
    for attempt in 1 2 3 4 5 6 7 8; do \
        composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader && exit 0; \
        sleep 5; \
    done; exit 1
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache storage/framework/sessions storage/framework/views \
        storage/logs test-results \
    && chown -R www-data:www-data bootstrap/cache database storage test-results
ENV APP_ENV=testing AI_MOCK_MODE=true PYTHON_BIN=python3 GEO_TEST_TITLE_PYTHON=python3
USER www-data
ENTRYPOINT ["sh", "/app/scripts/test-container.sh"]

FROM php-runtime-base AS runtime

WORKDIR /app

COPY . .
COPY --from=php-dependencies /app/vendor ./vendor
COPY --from=php-dependencies /app/bootstrap/cache ./bootstrap/cache
COPY --from=frontend-assets /app/public/build ./public/build
COPY --from=skill-assets /skills /app/yao-geo-skills
COPY --from=frontend-assets /npm-licenses /app/licenses/npm
COPY --from=skill-assets /font-licenses /app/licenses/report-fonts

RUN python3 scripts/collect-licenses.py runtime /app /app/licenses/runtime \
    && test -s LICENSE && test -s NOTICE && test -s THIRD_PARTY_NOTICES.md \
    && test -s skill-overlays/LICENSE && test -s yao-geo-skills/LICENSE

RUN test -f vendor/autoload.php \
    && setcap -r /usr/local/bin/frankenphp \
    && chmod -R a+rX /app \
    && mkdir -p \
        bootstrap/cache \
        database \
        instance \
        storage/app/private \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache database storage instance

# 用仓库自带的 Caddyfile 覆盖镜像自带的那份（2026-08-21 评审后加）。
#   镜像自带的 /etc/frankenphp/Caddyfile 把访问日志整段注释掉了，而那一段**环境变量打不开**
#   （FRANKENPHP_CONFIG 只进 frankenphp{} 全局块，CADDY_EXTRA_CONFIG 在站点块外面）。
#   不覆盖的后果实测过：换完之后 docker logs geo-saas 除了启动横幅什么都没有，
#   而换之前一小时 98 行日志里 87 行是访问记录。差异与加固点见 docker/Caddyfile 文件头。
#   ⚠️ CMD 里 --config 指的就是这个路径，改文件名要两边一起改。
COPY --chmod=0644 docker/Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD php -r '$body=@file_get_contents("http://127.0.0.1:8000/up"); exit($body === false ? 1 : 0);'

USER www-data

ENV SERVER_NAME=":8000"
ENTRYPOINT ["sh", "/app/docker/entrypoint.sh"]
CMD ["web"]
