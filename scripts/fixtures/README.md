# 合成 PDF 测试材料

`generate-pdfs.py` 生成 `tests/fixtures/pdf/` 中的全部六个 PDF。所有文字均为虚构示例，图片由 Pillow 绘制纯色块和线条；不读取旧 PDF、截图、照片或网络资源。

`pdf-specs.json` 只保存数字规格：页数、MediaBox、CropBox、旋转、非空白字符数，以及图片/透明遮罩的像素尺寸和每英寸像素数。这些参数保留现有的文本层、视觉提取及图片面积判定边界；重叠图片的累计覆盖率允许大于 1。

在仓库根目录使用项目的 test 镜像重新生成：

```sh
docker buildx build --load --target test --build-context test-sources=./tests -t yanji:test .
docker run --rm --network none --user 0 --entrypoint python3 \
  --mount "type=bind,src=$PWD,dst=/source" --workdir /source \
  yanji:test scripts/fixtures/generate-pdfs.py
```

也可以使用 ReportLab 5.0.0、Pillow 13.0.0 和 pypdf 7.0.1 执行 `python3 scripts/fixtures/generate-pdfs.py`。相同依赖版本下重复生成应得到相同字节；升级依赖后以 Poppler 实际提取结果及测试为准。

生成后执行 `python3 scripts/test-linux.py`，并逐页渲染检查。不要为适配新材料放宽 `PdfPageAnalyzerTest`、`DocumentIngestionVisionTest`、`KnowledgeDocumentAttributionTest` 或 `VisionPageExtractorTest` 的断言。
