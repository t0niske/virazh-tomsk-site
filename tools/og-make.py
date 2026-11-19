# Картинка для соцсетей (og:image) 1200×630: сцена из Codex (design/og/*-scene.png, предметы справа, слева пусто)
# + заголовок шрифтом сайта (Montserrat 900 italic, капс; строка со * — акцентным красным --red).
#   py -3 tools/og-make.py design/og/og-spravka-scene.png img/og/spravka-na-prava-v-tomske.jpg "СПРАВКА|НА ПРАВА|*В ТОМСКЕ" "Врачи, адреса и срок действия" [сдвиг кадра 0..1]
# В странице: meta «og: /img/og/<адрес>.jpg» (build.pl подставит в og:image) и "image" в JSON-LD Article.
import os, sys, tempfile
from PIL import Image, ImageDraw, ImageFont
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CACHE = os.path.join(tempfile.gettempdir(), 'virazh-og-fonts')
os.makedirs(CACHE, exist_ok=True)

def ttf(style, weight, sub):
    out = os.path.join(CACHE, f'mont-{style}-{weight}-{sub}.ttf')
    if not os.path.exists(out):
        f = TTFont(os.path.join(ROOT, 'assets', 'fonts', f'montserrat-{style}-{sub}.woff2'))
        if 'fvar' in f: f = instancer.instantiateVariableFont(f, {'wght': weight})
        f.flavor = None; f.save(out)
    return out

src, dst, title, sub = sys.argv[1:5]
yshift = float(sys.argv[5]) if len(sys.argv) > 5 else 0.5
W, H = 1200, 630
HEAD, RED, INK, MUTED = (56, 56, 56), (158, 18, 32), (30, 30, 30), (110, 110, 110)   # --head, --red, --ink, --muted

im = Image.open(src).convert('RGB')
s = max(W / im.width, H / im.height)
im = im.resize((round(im.width * s), round(im.height * s)), Image.LANCZOS)
x0 = (im.width - W) // 2; y0 = round((im.height - H) * yshift)
im = im.crop((x0, y0, x0 + W, y0 + H))
d = ImageDraw.Draw(im)

KIND = {'title': ('italic', 900), 'label': ('normal', 800), 'text': ('normal', 600)}
def font(kind, size, ch):
    style, weight = KIND[kind]
    return ImageFont.truetype(ttf(style, weight, 'latin' if ord(ch) < 0x400 else 'cyrillic'), size)
def width(text, kind, size): return sum(font(kind, size, c).getlength(c) for c in text)
def draw(x, y, text, kind, size, fill):
    for c in text:
        f = font(kind, size, c); d.text((x, y), c, font=f, fill=fill); x += f.getlength(c)

L, MAXW = 72, 560
lines = title.split('|')
size = 76
while max(width(l.lstrip('*'), 'title', size) for l in lines) > MAXW: size -= 1
lh = round(size * 1.06); label, subs = 24, 28
y = (H - (label + 34 + lh * len(lines) + 30 + subs)) // 2 - 6
draw(L, y, 'Автошкола «Авто-Вираж»', 'label', label, RED); y += label + 34
for l in lines:
    draw(L, y, l.lstrip('*'), 'title', size, RED if l.startswith('*') else HEAD); y += lh
draw(L, y + 30 - (lh - size), sub, 'text', subs, INK)
draw(L, H - 64, 'virazh-tomsk.ru', 'text', 22, MUTED)
im.save(dst, 'JPEG', quality=86, optimize=True, progressive=True)
print(dst, im.size, 'заголовок', size)
