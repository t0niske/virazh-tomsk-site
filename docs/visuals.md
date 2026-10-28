# Предметы первого экрана и 3D-знак: как сделаны

## Предмет-фото (ChatGPT, тот же стиль, что у фото-иконок)

Общий стиль промпта (на английском, так модель точнее держит фотореализм):

```
Professional studio product photography for a catalogue, like an advertisement for car accessories. This is a REAL PHOTOGRAPH of real objects, not a 3D render, not an illustration, not an icon. Real materials and textures: leather, metal, paper, matte plastic, subtle natural wear. Colours: brand red (#E2232B) accents, graphite and black, white; no other bright colours. Lighting: soft studio light from a large softbox top-left, neutral white colour temperature, no warm yellow cast. Camera: three-quarter view from above, about 30 degrees, the whole composition fully in frame, filling about 80% of the frame, centred. Background: pure seamless white #FFFFFF with NO shadow under the objects. Sharp focus across all objects (f/11). No people, no hands, no readable text, no logos, no watermarks. Square 1024x1024.
```

| Файл | Страницы | Предмет |
|---|---|---|
| art-obuchenie | Обучение | красный учебник, ключ, конус |
| art-akpp | Категория B, АКПП | селектор АКПП (P R N D) |
| art-mkpp | Категория B, МКПП | рычаг МКПП с кожаным чехлом |
| art-moto | Категория A | мотошлем и перчатки |
| art-ai | Категория AI | квадроцикл |
| art-online | Учись онлайн | ноутбук и телефон |
| art-kursy | Курсы для компаний | сигнальный жилет и планшет с документами |
| art-ekzameny | Экзамены | экзаменационный лист и секундомер |
| art-oplata | Оплата | кошелёк и ключ с красным брелоком |
| art-instruktory | Инструкторы | руль и очки |
| art-avtopark | Автопарк | связка ключей |
| art-kontakty | Контакты | метка на карте |
| art-blog | Блог и статьи | блокнот, ручка, ключ |
| art-svedeniya | Все страницы «Сведений» | папка с документами и печать |
| art-404 | Страница 404 | опрокинутый конус |

## 3D-знак «У» (Blender)

Опознавательный знак «Учебное транспортное средство»: равносторонний треугольник со скруглёнными углами, сторона 450 мм, красная кайма 1/10 стороны, буква «У» чёрная. Пластина 6 мм, глянцевое покрытие, цвет каймы — фирменный #E2232B. Рендер Cycles, прозрачный фон.

- Кадры поворота: от −62° до +10° вокруг вертикальной оси, 25 штук по 400 px.
- Облачный Blender ограничен 5 минутами на операцию, поэтому кадры считались частями по 7.

## Сцена первого экрана главной: «вираж трека»

Сгенерирована в ChatGPT (навык codex-image) редактированием картинки: на белом холсте 1672×941 стоит наша Omoda (`design/hero-scene/canvas.png`, машина на 690, 467, ширина 940), модель достроила вокруг неё студию — глянцевый пол с отражением и белый наклонный поворот трека с бордовой полосой по кромке. Промпт — `design/hero-scene/p-bank.txt`, ответ модели — `sc-bank.png`.

Модель перерисовывает машину, поэтому поверх кладём исходное фото: `py -3 tools/scene-comp.py design/hero-scene/sc-bank.png design/hero-scene/sc-bank-comp.jpg img/hero-omoda.webp design/hero-scene/carpos.txt` (сам находит сдвиг, кладёт исходник только выше порогов — тень и отражение под машиной остаются из сцены, иначе под днищем выходит чёрная подложка исходника).

У исходной Omoda красный салон, через лобовое стекло он читался как красный фон за окном. Его убирает `py -3 tools/car-windows.py` (внутри контура стёкол цвет уходит в тёмно-серый, красный корпус зеркала не трогается): уже применён к `img/hero-omoda.webp`, `img/hero-scene.webp` и `img/hero-scene-m.webp`, команды для сцены в шапке скрипта.

Из `sc-bank-comp.jpg` получены картинки сайта: почти белое доводится до чистого белого, края плавно уходят в белый (на компьютере — справа, сверху и снизу; на телефоне — обрезка 760…1672 × 110…941 и все четыре края). На странице сцена лежит под колонками первого экрана и привязана к его низу: колёса всегда чуть выше панели цены (`.hx-scene` в `assets/css/pages.css`).
