GEO self-hosted application fonts

Noto Sans SC: 101 Unicode-range WOFF2 subsets, variable weight 100-900.
Roboto Mono: 6 WOFF2 subsets, variable weight 100-700.
CSS aliases: GEO Sans and GEO Mono (no local() lookup).

Both fonts are distributed under SIL Open Font License 1.1. The original copyright
notices and licenses are included in notosanssc/OFL.txt and robotomono/OFL.txt.
The fonts may be bundled with commercial software under that license; they are not
sold on their own. Application content is not placed under the font license.

manifest.json records official download URLs, sizes and SHA256 hashes. Runtime
requests use same-origin /fonts/ paths with content-hashed filenames. No Google
Fonts connection or user-installed font is required. unicode-range lets browsers
load only the subsets needed by a page. All subsets are retained for dynamic text.

Refresh explicitly with python scripts/vendor-ui-fonts.py. Normal builds and page
loads never run the downloader. Keep this file and both OFL.txt files in releases.
