#!/usr/bin/env python3
"""Generate all six PDF fixtures from fictional text and drawn geometric images.

No original document, screenshot, photograph or external asset is read. The
numeric specifications retain page boxes, rotation, text counts and Poppler's
image-area inputs so existing analyzer/ingestion assertions stay unchanged.

Reproduce with the project's Docker test image (reportlab, Pillow and pypdf),
or those same Python package versions locally:
    python3 scripts/fixtures/generate-pdfs.py
"""
from io import BytesIO
import json
from pathlib import Path
import re
import textwrap

from PIL import Image, ImageDraw
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen.canvas import Canvas

ROOT = Path(__file__).resolve().parents[2]
SPECS = Path(__file__).with_name('pdf-specs.json')


def fictional_text(length):
    """Fit exact non-whitespace counts without hidden or off-page text."""
    if not length:
        return ''
    words = ('Fictional sample. Red blocks and blue lines describe an imaginary '
             'workshop. All names and figures are invented for software tests. ').split()
    output = []
    remaining = length
    while remaining:
        word = words[len(output) % len(words)]
        if len(word) > remaining:
            word = 'X' * remaining
        output.append(word)
        remaining -= len(word)
    result = ' '.join(output)
    assert len(re.sub(r'\s+', '', result)) == length
    return result


def geometric_image(width, height, alpha, variant):
    mode = 'RGBA' if alpha else 'RGB'
    image = Image.new(mode, (width, height), (221, 238, 246, 255) if alpha else (221, 238, 246))
    draw = ImageDraw.Draw(image)
    line = max(1, min(width, height) // 100)
    colors = [(42, 107, 142), (215, 122, 66), (67, 131, 109)]
    for i in range(1, 7):
        color = colors[(variant + i) % len(colors)]
        # A varying alpha plane preserves explicit image-mask pairs in fixtures.
        if alpha:
            color += (190 + i * 8,)
        x = width * i // 8
        draw.rectangle((x, height // 4, min(width - 1, x + max(1, width // 16)), height * 3 // 4), fill=color)
    draw.line((0, height // 2, width - 1, height // 2), fill=(40, 73, 91, 255) if alpha else (40, 73, 91), width=line)
    return image


def create_fixture(spec, destination):
    buffer = BytesIO()
    canvas = Canvas(buffer, invariant=1, pageCompression=1)
    canvas.setAuthor('Yanji test fixtures')
    canvas.setTitle('Fictional geometric PDF test fixture')
    canvas.setSubject('Synthetic content only; generated from numeric test specifications')
    for number, page in enumerate(spec['pages'], 1):
        left, bottom, right, top = page['mediabox']
        width, height = right - left, top - bottom
        canvas.setPageSize((width, height))
        images = page['images']
        for index, item in enumerate(images):
            if item['type'] == 'smask':
                continue  # emitted with the preceding RGBA image
            has_mask = index + 1 < len(images) and images[index + 1]['type'] == 'smask'
            pixels_w, pixels_h = item['pixels']
            xppi, yppi = item['ppi']
            drawn_w, drawn_h = pixels_w * 72 / xppi, pixels_h * 72 / yppi
            image = geometric_image(pixels_w, pixels_h, has_mask, index + number)
            # Overlapping and page-spanning images intentionally preserve the
            # >1 summed image coverage contract; do not clamp it in the fixture.
            x = (width - drawn_w) / 2
            y = max(18, (height - drawn_h) / 2)
            canvas.drawImage(ImageReader(image), x, y, width=drawn_w, height=drawn_h,
                             mask='auto' if has_mask else None)
        text = fictional_text(page['chars'])
        if text:
            lines = textwrap.wrap(text, width=82)
            panel_height = len(lines) * 14 + 24
            canvas.setFillColorRGB(1, 1, 1)
            canvas.rect(25, height - 25 - panel_height, width - 50, panel_height, fill=1, stroke=0)
            canvas.setFillColorRGB(0.08, 0.15, 0.18)
            canvas.setFont('Helvetica', 10)
            for row, line in enumerate(lines):
                canvas.drawString(36, height - 44 - row * 14, line)
        canvas.showPage()
    canvas.save()
    reader = PdfReader(buffer)
    writer = PdfWriter()
    for index, source_page in enumerate(reader.pages):
        page = writer.add_page(source_page)
        target = spec['pages'][index]
        page.mediabox = RectangleObject(target['mediabox'])
        page.cropbox = RectangleObject(target['cropbox'])
        if target['rotate']:
            page.rotate(target['rotate'])
    writer.add_metadata({'/Author': 'Yanji test fixtures', '/Title': 'Fictional geometric PDF test fixture',
                         '/Subject': 'Synthetic test data; no real people or third-party images'})
    with destination.open('wb') as stream:
        writer.write(stream)


def main():
    output = ROOT / 'tests/fixtures/pdf'
    output.mkdir(parents=True, exist_ok=True)
    specs = json.loads(SPECS.read_text())
    for spec in specs:
        create_fixture(spec, output / spec['file'])
        print(spec['file'], len(spec['pages']), 'synthetic pages')


if __name__ == '__main__':
    main()
