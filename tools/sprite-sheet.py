# Собирает ракурсы 3D-знака (design/3d/frames/f00..f24.png) в одну картинку 5×5 для сайта.
# Все кадры обрезаются одной общей рамкой, чтобы знак не прыгал при смене кадра.
#   py -3 tools/sprite-sheet.py design/3d/frames img/art/sign-u-sprite.webp 360
import sys, glob
from PIL import Image
src, dst = sys.argv[1], sys.argv[2]
cell = int(sys.argv[3]) if len(sys.argv) > 3 else 360
frames = [Image.open(f).convert('RGBA') for f in sorted(glob.glob(src + '/f*.png'))]
assert len(frames) == 25, len(frames)
box = None
for im in frames:
    b = im.getchannel('A').point(lambda a: 255 if a > 8 else 0).getbbox()
    box = b if box is None else (min(box[0], b[0]), min(box[1], b[1]), max(box[2], b[2]), max(box[3], b[3]))
w, h = box[2] - box[0], box[3] - box[1]
side = int(max(w, h) * 1.04)
cx, cy = (box[0] + box[2]) // 2, (box[1] + box[3]) // 2
crop = (cx - side // 2, cy - side // 2, cx - side // 2 + side, cy - side // 2 + side)
sheet = Image.new('RGBA', (cell * 5, cell * 5), (0, 0, 0, 0))
for i, im in enumerate(frames):
    c = Image.new('RGBA', (side, side), (0, 0, 0, 0))
    c.paste(im.crop(crop), (0, 0))
    sheet.paste(c.resize((cell, cell), Image.LANCZOS), ((i % 5) * cell, (i // 5) * cell))
sheet.save(dst, 'WEBP', quality=86, method=6)
print(dst, sheet.size, 'crop', crop)
