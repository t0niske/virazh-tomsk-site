# Машина первого экрана главной: Omoda + глянцевый пол с отражением и тенью из сцены «вираж», без самого фона
# (белый трек с бордовой полосой убирается) — картинка с прозрачностью, ложится на любой светлый фон.
#   py -3 tools/car-windows.py design/hero-scene/sc-bank-comp.jpg design/hero-scene/sc-bank-fixed.png 940 690 467
#   py -3 tools/car-floor.py design/hero-scene/sc-bank-fixed.png img/hero-omoda.webp img/hero-car.webp
# Над полом — только сама машина (по маске выреза); пол переводится в «тёмное поверх прозрачного»:
# на белом фоне выглядит ровно как в сцене, светлый фон сцены исчезает.
import sys
import numpy as np
from PIL import Image, ImageFilter

scene, carf, out = sys.argv[1:4]
CX, CY, CW = 690, 467, 940                 # где стоит машина в сцене (design/hero-scene/carpos.txt)
X0, Y0, X1, Y1 = 560, 420, 1672, 941       # кадр: машина и пол вокруг
HZ0, HZ1 = 655, 735                        # горизонт: выше — фон сцены (прозрачно), ниже — пол

sc = np.asarray(Image.open(scene).convert('RGB')).astype(np.float32)
H, W = sc.shape[:2]
car = Image.open(carf).convert('RGBA')
car = car.resize((CW, round(car.height * CW / car.width)), Image.LANCZOS)
cm = Image.new('L', (W, H), 0); cm.paste(car.getchannel('A'), (CX, CY))
cm = np.asarray(cm.filter(ImageFilter.GaussianBlur(.8))).astype(np.float32) / 255

dark = 1 - sc.min(axis=2) / 255                                  # насколько пиксель темнее белого
yy = np.arange(H, dtype=np.float32)[:, None]
floor = np.clip((yy - HZ0) / (HZ1 - HZ0), 0, 1) * np.ones((1, W), np.float32)
xx = np.arange(W, dtype=np.float32)[None, :]
fade = np.clip((xx - X0) / 140, 0, 1) * np.clip((X1 - xx) / 50, 0, 1) * np.clip((Y1 - yy) / 120, 0, 1)
a = np.maximum(cm, np.clip(dark * 1.15, 0, 1) * floor * fade)
a = np.clip(a, 0, 1)
# цвет так, чтобы поверх белого получился исходный пиксель сцены
rgb = np.where(a[..., None] > 1e-3, (sc - (1 - a[..., None]) * 255) / np.maximum(a[..., None], 1e-3), 0)
rgb = np.clip(rgb, 0, 255)
res = np.dstack([rgb, a * 255]).astype(np.uint8)[Y0:Y1, X0:X1]
Image.fromarray(res, 'RGBA').save(out, quality=86, method=6) if out.endswith('.webp') else Image.fromarray(res, 'RGBA').save(out, optimize=True)
print(out, res.shape)
