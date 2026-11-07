# Кусочки карты для блока «Три офиса» на главной — с нашего сайта, без запросов посетителей к OpenStreetMap.
# Один раз скачивает тайлы OSM для района офисов (приближения 12–15), делает их серыми (как была CSS-заливка)
# и сохраняет в img/map/{z}/{x}/{y}.webp. Границы — те же, что в site.js (MAP_BOUNDS). Данные © участники OpenStreetMap (ODbL).
#   py -3 tools/map-tiles.py
import math, os, time, io, urllib.request
from PIL import Image, ImageEnhance

S, N, W, E = 56.445, 56.535, 84.90, 85.08
ZOOMS = range(12, 16)
UA = 'virazh-tomsk-site/1.0 (one-time map tiles for school offices map; contact via site)'
OUT = os.path.join(os.path.dirname(__file__), '..', 'img', 'map')

def tile(lat, lon, z):
    n = 2 ** z
    return int((lon + 180) / 360 * n), int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n)

def gray(png):
    im = Image.open(io.BytesIO(png)).convert('L').convert('RGB')
    im = ImageEnhance.Contrast(im).enhance(1.05)
    return ImageEnhance.Brightness(im).enhance(1.04)

got = skip = 0
for z in ZOOMS:
    x0, y0 = tile(N, W, z); x1, y1 = tile(S, E, z)
    for x in range(x0, x1 + 1):
        for y in range(y0, y1 + 1):
            path = os.path.join(OUT, str(z), str(x), f'{y}.webp')
            if os.path.exists(path):
                skip += 1; continue
            req = urllib.request.Request(f'https://tile.openstreetmap.org/{z}/{x}/{y}.png', headers={'User-Agent': UA})
            png = urllib.request.urlopen(req, timeout=30).read()
            os.makedirs(os.path.dirname(path), exist_ok=True)
            gray(png).save(path, 'WEBP', quality=78, method=6)
            got += 1; time.sleep(0.15)
print('скачано', got, 'уже было', skip)
