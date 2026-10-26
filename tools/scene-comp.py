# Наложить оригинальную машину на сгенерированную сцену: ищем сдвиг/масштаб, при котором машина совпадает лучше всего
import sys
from PIL import Image, ImageChops
import numpy as np
scene_f, out_f, car_f = sys.argv[1], sys.argv[2], sys.argv[3]
x0, y0, cw, ch = map(int, open(sys.argv[4]).read().split())
sc = Image.open(scene_f).convert('RGB')
if sc.size != (1672, 941): sc = sc.resize((1672, 941), Image.LANCZOS)
car = Image.open(car_f).convert('RGBA')
A = np.asarray(sc.convert('L'), dtype=np.float32)
best = None
for s in (0.96, 0.98, 1.0, 1.02, 1.04):
    w = round(cw * s); h = round(car.height * w / car.width)
    c = car.resize((w, h), Image.LANCZOS)
    a = np.asarray(c.split()[3], dtype=np.float32) / 255
    m = a > .9
    g = np.asarray(c.convert('L'), dtype=np.float32)
    cx, cy = x0 + cw / 2 - w / 2, y0 + ch / 2 - h / 2
    for dx in range(-40, 41, 4):
        for dy in range(-40, 41, 4):
            X, Y = round(cx + dx), round(cy + dy)
            if X < 0 or Y < 0 or X + w > 1672 or Y + h > 941: continue
            d = np.abs(A[Y:Y + h, X:X + w][m] - g[m]).mean()
            if best is None or d < best[0]: best = (d, s, X, Y, w, h)
d, s, X, Y, w, h = best
# доуточнить шагом 1
c = car.resize((w, h), Image.LANCZOS); a = np.asarray(c.split()[3], dtype=np.float32) / 255; m = a > .9; g = np.asarray(c.convert('L'), dtype=np.float32)
for dx in range(-3, 4):
    for dy in range(-3, 4):
        XX, YY = X + dx, Y + dy
        if XX < 0 or YY < 0 or XX + w > 1672 or YY + h > 941: continue
        dd = np.abs(A[YY:YY + h, XX:XX + w][m] - g[m]).mean()
        if dd < best[0]: best = (dd, s, XX, YY, w, h)
d, s, X, Y, w, h = best
print(f'diff {d:.1f} scale {s} pos {X},{Y} size {w}x{h}')
# машину кладём только по её непрозрачной части (тень из сцены остаётся сценовой)
body = c.copy(); al = np.asarray(c.split()[3]).copy().astype(np.float32); al[al < 200] = 0
# ниже порога машины (пороги, низ бампера, колёса снизу) оставляем сцену: там её тень и отражение, а у исходника — тёмная подложка
CUT0, CUT1 = 700, 716                                # строки холста: до CUT0 исходник, к CUT1 плавно уходит в сцену
rows = np.arange(h)[:, None] + Y
al *= np.clip((CUT1 - rows) / (CUT1 - CUT0), 0, 1)
al = al.astype(np.uint8)
body.putalpha(Image.fromarray(al))
out = sc.convert('RGBA'); out.alpha_composite(body, (X, Y))
out.convert('RGB').save(out_f, quality=90)
