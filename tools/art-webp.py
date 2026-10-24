# Готовит предмет-фото для первого экрана: обрезает пустые поля по прозрачности,
# ставит предмет по центру квадрата с небольшим отступом и сохраняет WebP 720×720.
#   py -3 tools/art-webp.py design/art/cut/art-ekzameny.png img/art/art-ekzameny.webp
import sys
from PIL import Image
src, dst = sys.argv[1], sys.argv[2]
size = int(sys.argv[3]) if len(sys.argv) > 3 else 720
im = Image.open(src).convert('RGBA')
box = im.getchannel('A').point(lambda a: 255 if a > 8 else 0).getbbox()
im = im.crop(box)
side = int(max(im.size) * 1.06)
sq = Image.new('RGBA', (side, side), (0, 0, 0, 0))
sq.paste(im, ((side - im.width) // 2, (side - im.height) // 2))
sq = sq.resize((size, size), Image.LANCZOS)
sq.save(dst, 'WEBP', quality=84, method=6)
print(dst, sq.size)
