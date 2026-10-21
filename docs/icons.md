# Иконки для сайта «Авто-Вираж»: промпты для генерации

Все иконки — **один набор в одном стиле**. Поэтому у каждой иконки промпт = **общий стиль** + **описание предмета**. Генерируйте по одной иконке, в одной и той же модели (Midjourney, Flux, DALL·E, Ideogram, Kandinsky — неважно, главное одна на весь набор). Промпты на английском: так модели точнее держат стиль.

**Что прислать:** картинку 1024×1024 или больше на белом фоне (webp, png или jpg) с названием из таблицы — или просто напишите, какая это иконка. Я уберу фон и встрою её в блок из колонки «Где».

## Эталон стиля

Первая готовая иконка — `step-driving` (руль) — стала эталоном: полуреалистичный 3D-рендер, белый пластик, графитовая перфорированная кожа, фирменные красные акценты. Она уже на сайте, в шаге «Вождение». Остальные иконки делайте **в этом же стиле**: в Midjourney — `--sref` на картинку руля, в других моделях — приложите руль как референс и допишите `in the exact same style as the reference image`.

Что учесть по опыту руля:

- **Меньше чёрного.** На тёмном блоке большие графитовые поверхности сливаются с фоном. Основной цвет предмета — белый или светло-серый, графит — только детали, красный — акценты.
- **Крупные формы, мало мелочей.** На сайте иконка 72–96 px: перфорация и тонкие детали не читаются, важен силуэт.
- **Фон — чисто белый.** Прозрачный не обязателен: белый фон, в том числе внутри предмета (как между спицами руля), убирает скрипт `tools/icon-cut.ps1`.
- **Ракурс как у руля:** почти фронтально, лёгкий наклон, предмет по центру, занимает 80–85% кадра.

## Общий стиль (вставлять в начало каждого промпта)

```
premium semi-realistic 3D product icon, clean studio render, smooth white and light-grey matte plastic as the main material,
deep graphite #2B2B2B perforated leather or rubber only as small details, glossy signal red #E2232B accents,
soft diffused studio lighting from the top-left, subtle reflections, gentle soft shadows,
front view with a slight three-quarter tilt, single object centered, object fills about 85% of the frame,
bold readable silhouette at 72 px, pure white #FFFFFF background, no text, no letters, no numbers, no logos, no watermark,
consistent icon set, 1024x1024
```

## Негативный промпт (если модель поддерживает)

```
text, letters, numbers, typography, logo, watermark, realistic human, face, hands, busy background,
gradient background, scene, multiple objects, flat 2D, line art, outline icon, sketch, noise, blur, cropped object,
neon, purple, blue, green, orange, yellow (except the single gold star where specified)
```

## 1. Как проходит обучение — 5 шагов (тёмный блок «дорога из шагов»)

Эти иконки стоят на **тёмном фоне** — следите, чтобы у предмета не было чёрных краёв, сливающихся с фоном (графит используйте только как деталь).

| Файл | Где | Описание предмета (добавить после общего стиля) |
|---|---|---|
| `step-contract.png` | Шаг 01 «Заявка и договор» | `a white paper contract sheet with a few grey text lines (no readable text), a red round checkmark badge in the corner, a graphite fountain pen lying diagonally across the sheet` |
| `step-theory.png` | Шаг 02 «Теория ПДД» | `an open graphite laptop, on its screen a simple white triangular road warning sign with a red border (empty, no symbol inside), a small red play-button bubble floating near the screen` |
| `step-driving.png` | Шаг 03 «Вождение» | ✅ готово — эталон стиля |
| `step-inner-exam.png` | Шаг 04 «Внутренний экзамен» | `a white clipboard with a graphite clip, three checklist rows with red round checkmarks, a small red-and-white traffic cone standing next to it` |
| `step-license.png` | Шаг 05 «Экзамен в ГИБДД и права» | `a driver's license plastic card with a red top stripe and a grey photo placeholder square (no text, no face), a small red-and-white checkered finish flag on a pole behind the card` |

## 2. Оплата и скидки (блок «Всю сумму сразу платить не нужно», страница «Оплата»)

| Файл | Где | Описание предмета |
|---|---|---|
| `perk-student.png` | «Скидка студентам 3 000 ₽» | `a graphite graduation mortarboard cap with a red tassel, resting on a small stack of two white books` |
| `perk-tax.png` | «Налоговый вычет 13%» | `a small stack of three red coins with a white curved arrow looping around them, suggesting money coming back` |
| `perk-matcap.png` | «Материнский капитал» | `a white baby stroller with a red hood, a single red coin floating above it` |
| `perk-holiday.png` | «Скидки к праздникам» | `a white gift box tied with a glossy red ribbon and bow, slightly opened lid` |

## 3. Цифры и доверие (блок со статистикой и «Официальная автошкола»)

| Файл | Где | Описание предмета |
|---|---|---|
| `trust-rating.png` | «5.0 в 2ГИС» | `a single glossy gold star (the only gold element of the set) sitting on a white rounded speech bubble` |
| `trust-years.png` | «С 2007 года» | `a white desk calendar with a red top binding and an empty page (no numbers), a small red laurel branch leaning on it` |
| `trust-fleet.png` | «10 машин» | `a compact white driving-school sedan with two red side stripes and a small red roof sign shaped like a triangle (no letters), three-quarter front view` |
| `trust-autodrome.png` | «Свой автодром» | `a short curved piece of graphite asphalt road with a white dashed center line, two small red-and-white traffic cones on it` |
| `trust-license.png` | «Лицензия и заключение ГИБДД» | `a white certificate document with a red wax seal and a red ribbon, partially rolled at the bottom edge` |

## 4. Категории обучения (страница «Обучение», карточки программ)

| Файл | Где | Описание предмета |
|---|---|---|
| `cat-b-auto.png` | Категория B на автомате | `an automatic transmission gear selector lever, graphite with a red glowing accent strip, mounted in a white console (no letters on the console)` |
| `cat-b-manual.png` | Категория B на механике | `a round graphite manual gear-shift knob with a red ring, on a short chrome stick, rising from a white leather boot (no pattern letters)` |
| `cat-a-moto.png` | Категория A, мотоцикл | `a small classic red-and-white motorcycle, three-quarter side view, graphite tyres and seat` |
| `cat-ai.png` | Квадроцикл и снегоход (AI) | `a compact red ATV quad bike with chunky graphite tyres, three-quarter front view` |
| `cat-pro.png` | Курсы для транспортных компаний | `a white box truck cab with a red stripe, three-quarter front view, next to a small graphite headset with a microphone` |
| `cat-online.png` | Учись онлайн | `a white smartphone standing upright, on its screen a red rounded play button, a small graphite graduation cap resting on the top edge of the phone` |

## 5. Контакты (страница «Контакты», офисы)

| Файл | Где | Описание предмета |
|---|---|---|
| `ct-phone.png` | Телефон | `a glossy red classic phone handset, tilted, with two small white sound-wave arcs` |
| `ct-pin.png` | Адреса офисов | `a red map location pin standing on a small white round map tile with a graphite curvy road drawn on it` |
| `ct-clock.png` | Режим работы | `a round white wall clock with a red rim and graphite hands (no numbers on the dial)` |
| `ct-mail.png` | Почта | `a white envelope with a red round wax seal and a small red notification dot on the corner` |

## Советы

- Красный должен быть именно фирменным `#E2232B`, без ухода в оранжевый или розовый.
- Не нужно ни букв, ни цифр — модели их искажают; все подписи и суммы уже есть на сайте текстом.
