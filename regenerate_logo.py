from pathlib import Path
import re

from PIL import Image, ImageOps
import vtracer

SOURCE = Path('new logo.png')
PUBLIC = Path('public')
PRIMARY = '#FFB900'
MARGIN_RATIO = 0.015

image = Image.open(SOURCE).convert('RGB')
luminance = image.convert('L')
mask = luminance.point(lambda value: 255 if value > 100 else 0)
bbox = mask.getbbox()
if bbox is None:
    raise RuntimeError('No logo shape found.')

mask = mask.crop(bbox)
margin = max(1, round(max(mask.size) * MARGIN_RATIO))
framed = Image.new('L', (mask.width + margin * 2, mask.height + margin * 2), 0)
framed.paste(mask, (margin, margin))

trace_input = Image.new('L', framed.size, 255)
trace_input.paste(0, mask=framed)
temporary = PUBLIC / '_logo-trace.png'
trace_input.save(temporary)

svg = vtracer.convert_raw_image_to_svg(
    temporary.read_bytes(),
    img_format='png',
    colormode='binary',
    mode='spline',
    filter_speckle=4,
    corner_threshold=60,
    length_threshold=4,
    max_iterations=10,
    splice_threshold=45,
    path_precision=8,
)
temporary.unlink()

svg = re.sub(r'<\?xml[^?]*\?>|<!--.*?-->', '', svg, flags=re.DOTALL).strip()
svg = re.sub(r'\s+xmlns="http://www\.w3\.org/2000/svg"(?=[^>]*xmlns="http://www\.w3\.org/2000/svg")', '', svg, count=1)
svg = re.sub(r'<svg([^>]*)width="(\d+)" height="(\d+)"', r'<svg\1viewBox="0 0 \2 \3"', svg, count=1)
svg = re.sub(r'(<svg[^>]*>)', r'\1\n  <title>samedepo logo</title>', svg, count=1)
svg = re.sub(r'\s+stroke="[^"]*"', '', svg)
svg = re.sub(r'fill="[^"]*"', f'fill="{PRIMARY}"', svg)
svg = re.sub(r'>\s*<path', '>\n  <path', svg)
svg = re.sub(r'/><', '/>\n<', svg)

colors = {
    'logo.svg': PRIMARY,
    'logo-mark.svg': PRIMARY,
    'logo-dark.svg': PRIMARY,
    'logo-mark-dark.svg': PRIMARY,
    'logo-white.svg': '#FFFFFF',
    'logo-mark-white.svg': '#FFFFFF',
    'logo-muted.svg': '#A3A3A3',
    'logo-mark-muted.svg': '#A3A3A3',
}
for filename, color in colors.items():
    (PUBLIC / filename).write_text(svg.replace(PRIMARY, color), encoding='utf-8')

rgba = Image.new('RGBA', framed.size, (255, 185, 0, 0))
rgba.putalpha(framed)
side = max(rgba.size)
square = Image.new('RGBA', (side, side), (0, 0, 0, 0))
square.paste(rgba, ((side - rgba.width) // 2, (side - rgba.height) // 2), rgba)

def add_svg_padding(svg_content: str, padding: int) -> str:
    return re.sub(
        r'(<svg[^>]*?)viewBox="0 0 (\d+) (\d+)"',
        lambda m: f'{m.group(1)}viewBox="-{padding} -{padding} {int(m.group(2)) + padding * 2} {int(m.group(3)) + padding * 2}"',
        svg_content,
        count=1,
    )

favicon_svg = svg.replace('<title>samedepo logo</title>', '<title>samedepo favicon</title>')
favicon_svg = add_svg_padding(favicon_svg, 2)
(PUBLIC / 'favicon.svg').write_text(favicon_svg, encoding='utf-8')

FAVICON_EXTRA_PADDING = 2

for size in (16, 32, 48, 180, 192, 512):
    content_size = size - FAVICON_EXTRA_PADDING * 2
    resized = square.resize((content_size, content_size), Image.Resampling.LANCZOS)
    padded = ImageOps.expand(resized, border=FAVICON_EXTRA_PADDING, fill=(0, 0, 0, 0))
    padded.save(PUBLIC / f'favicon-{size}x{size}.png')

ico_source_size = 48
ico_content_size = ico_source_size - FAVICON_EXTRA_PADDING * 2
ico_source = square.resize((ico_content_size, ico_content_size), Image.Resampling.LANCZOS)
ico_source = ImageOps.expand(ico_source, border=FAVICON_EXTRA_PADDING, fill=(0, 0, 0, 0))
ico_source.save(
    PUBLIC / 'favicon.ico', format='ICO', sizes=[(16, 16), (32, 32), (48, 48)]
)

print(f'Generated assets from {SOURCE}: bbox={bbox}, margin={margin}px, favicon_padding={FAVICON_EXTRA_PADDING}px')
