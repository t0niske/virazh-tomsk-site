# Убрать красный салон за стёклами Omoda: внутри контура стёкол красные пиксели переводятся в серый той же яркости.
# Контуры заданы в координатах img/hero-omoda.webp (1782×765); для сцены первого экрана передаём, где там стоит машина.
#   py -3 tools/car-windows.py <вход> <выход> [ширина_машины X Y] [quality]
# Сцена:   py -3 tools/car-windows.py img/hero-scene.webp img/hero-scene.webp 939 691 467
# Телефон: py -3 tools/car-windows.py img/hero-scene-m.webp img/hero-scene-m.webp 939 -69 357
import sys
from PIL import Image, ImageDraw, ImageFilter
import numpy as np

SRC_W = 1782
GLASS = [  # лобовое стекло; боковые окна над зеркалом и за ним (сам красный корпус зеркала не трогаем)
    [(590, 156), (640, 112), (790, 34), (1066, 24), (1052, 100), (1038, 152)],
    [(1086, 34), (1405, 62), (1402, 146), (1204, 152), (1198, 96), (1086, 100)],
]

src, out = sys.argv[1], sys.argv[2]
k, ox, oy = 1.0, 0, 0
if len(sys.argv) >= 6:
    k, ox, oy = int(sys.argv[3]) / SRC_W, int(sys.argv[4]), int(sys.argv[5])
quality = int(sys.argv[6]) if len(sys.argv) >= 7 else 88

im = Image.open(src)
mode = im.mode
rgba = im.convert('RGBA')
mask = Image.new('L', rgba.size, 0)
d = ImageDraw.Draw(mask)
for poly in GLASS:
    d.polygon([(x * k + ox, y * k + oy) for x, y in poly], fill=255)
mask = mask.filter(ImageFilter.GaussianBlur(max(1, 3 * k)))

a = np.asarray(rgba).astype(np.float32)
r, g, b = a[..., 0], a[..., 1], a[..., 2]
lum = 0.299 * r + 0.587 * g + 0.114 * b
red = np.clip((r - np.maximum(g, b)) / 40, 0, 1)        # насколько пиксель красный
w = np.asarray(mask, dtype=np.float32) / 255             # за стеклом цвет убираем целиком, блики и так серые
grey = lum * (1 - 0.45 * red)                            # бывшие красные сиденья темнее: салон как за тонировкой
for i, base in enumerate((grey, grey * 1.01, grey * 1.04)):   # чуть холоднее, как стекло
    a[..., i] = a[..., i] * (1 - w) + base * w
res = Image.fromarray(np.clip(a, 0, 255).astype(np.uint8), 'RGBA')
res = res if mode == 'RGBA' else res.convert('RGB')
res.save(out, quality=quality, method=6)
print(f'{out}: изменено пикселей {int((w > .05).sum())}')
