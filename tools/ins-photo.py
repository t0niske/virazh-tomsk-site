# Фото инструкторов для карточек: человек по грудь на фоне своей учебной машины в студии «вираж», под дымкой 45%.
#   py -3 tools\ins-photo.py            — пересобрать все фото в img/ins
# Исходники: design/img-original/ins/<имя>.jpg (фото 600×600), design/ins-cut/<имя>.png (альфа-маска, rembg isnet-general-use),
# design/ins-scene/<машина>-studio.jpg (сцена вокруг машины из img/car-*.webp, сгенерирована через Codex).
# Новый инструктор: вырезать фон rembg → добавить в PEOPLE; нет фото — в NO_PHOTO (в карточке будет его машина).
import os
import numpy as np
from PIL import Image, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ORIG = os.path.join(ROOT, 'design', 'img-original', 'ins')
CUT = os.path.join(ROOT, 'design', 'ins-cut')
SCENES = os.path.join(ROOT, 'design', 'ins-scene')
OUT = os.path.join(ROOT, 'img', 'ins')

SIZE = 720       # сторона итогового квадрата
SRC = 600        # исходники 600×600
ZOOM = 1.3       # кадр по грудь: выцветший низ исходников уходит за край
TOP = 0.09       # отступ над макушкой, доля кадра
HAZE = 0.45      # плотность однотонной дымки над сценой

PEOPLE = {'ushakov': 'omoda', 'kovalenok': 'vw-polo', 'komissarov': 'belgee', 'volnykh': 'kia-rio',
          'novoselov': 'granta', 'volodin': 'granta'}
NO_PHOTO = {'yukalchuk': 'chery-tiggo-4', 'sofrin': 'jetour', 'solovyev': 'nissan-note'}


def box(a, r, axis):
    p = np.pad(a, [(r + 1, r) if k == axis else (0, 0) for k in range(2)], mode='edge')
    c = np.cumsum(p, axis=axis)
    hi = np.take(c, range(2 * r + 1, c.shape[axis]), axis=axis)
    lo = np.take(c, range(0, c.shape[axis] - 2 * r - 1), axis=axis)
    return (hi - lo) / (2 * r + 1)


def blur(a, r):
    """Почти гауссово размытие float-массива: три прохода прямоугольного фильтра."""
    k = max(1, int(round(r * 0.8)))
    for _ in range(3):
        a = box(box(a, k, 0), k, 1)
    return a


def person(name):
    """Человек без ореола: маска сужена на ~1 px, цвет края очищен от старого белого фона; кадр по грудь."""
    C = np.asarray(Image.open(os.path.join(ORIG, name + '.jpg')).convert('RGB'), float) / 255
    a = np.asarray(Image.open(os.path.join(CUT, name + '.png')).split()[3], float) / 255
    m = Image.fromarray((a * 255).astype(np.uint8)).filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(0.7))
    a = np.asarray(m, float) / 255
    w = (1 - a) ** 2
    B = np.stack([blur(C[..., k] * w, 12) for k in range(3)], -1) / np.maximum(blur(w, 12), 1e-4)[..., None]
    F = (C - (1 - a[..., None]) * B) / np.maximum(a[..., None], 0.05)
    F = np.where(a[..., None] > 0.97, C, np.clip(F, 0, 1))
    # квадрат L×L: макушка на TOP от верха, по горизонтали по центру головы
    L = int(SRC / ZOOM)
    top = np.where((a > 0.5).sum(1) > 6)[0][0]
    cx = int(np.mean(np.where(a[top:top + 60] > 0.5)[1]))
    x0, y0 = int(np.clip(cx - L / 2, 0, SRC - L)), max(0, top - int(TOP * L))
    crop = lambda arr: np.asarray(Image.fromarray((arr * 255).astype(np.uint8)).crop((x0, y0, x0 + L, y0 + L)).resize((SIZE, SIZE), Image.LANCZOS), float) / 255
    return crop(F), crop(a)


def scene(car):
    """Сцена с машиной: лёгкое размытие, как у портретного объектива, обесцвечивание и дымка плотнее книзу."""
    plate = Image.open(os.path.join(SCENES, car + '-studio.jpg')).convert('RGB').resize((SIZE, SIZE), Image.LANCZOS).filter(ImageFilter.GaussianBlur(2.5))
    bg = np.asarray(plate, float) / 255
    lum = (bg * [0.299, 0.587, 0.114]).sum(-1, keepdims=True)
    bg = bg * 0.3 + lum * 0.7
    y = np.arange(SIZE)[:, None, None] / SIZE
    k = np.clip(HAZE + (y - 0.45) * 0.35, HAZE * 0.85, 0.97)
    return bg * (1 - k) + np.array([0xF2, 0xF2, 0xF2]) / 255 * k


def save(img, name):
    Image.fromarray((img * 255).round().clip(0, 255).astype(np.uint8)).save(os.path.join(OUT, name + '.webp'), 'WEBP', quality=82, method=6)
    print(name)


for name, car in PEOPLE.items():
    F, a = person(name)
    save(F * a[..., None] + scene(car) * (1 - a[..., None]), name)
for name, car in NO_PHOTO.items():
    save(scene(car), name)
