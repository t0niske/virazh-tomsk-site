<?php

use App\Crm\View as V;
use App\Leads\Source;

/** @var array $errors @var array $old */
$value = static fn (string $key, string $default = ''): string => V::e($old[$key] ?? $default);
?>
<a class="back link" href="/crm">← Все заявки</a>
<section class="panel narrow">
  <h1>Новая заявка</h1>
  <p class="muted">Для тех, кто позвонил или пришёл в офис. Заявки с сайта и из MAX появляются сами.</p>
  <form method="post" action="/crm/new">
    <?= V::csrf() ?>
    <label class="field"><span>Имя</span>
      <input name="name" value="<?= $value('name') ?>" maxlength="100" required>
      <?php if (isset($errors['name'])): ?><em class="error"><?= V::e($errors['name']) ?></em><?php endif; ?>
    </label>
    <label class="field"><span>Телефон</span>
      <input name="phone" value="<?= $value('phone') ?>" inputmode="tel" placeholder="+7 (___) ___-__-__" required>
      <?php if (isset($errors['phone'])): ?><em class="error"><?= V::e($errors['phone']) ?></em><?php endif; ?>
    </label>
    <label class="field"><span>Услуга</span>
      <input name="service" value="<?= $value('service') ?>" maxlength="150" list="services" placeholder="Категория B (АКПП)">
      <datalist id="services">
        <option>Категория B (МКПП)</option><option>Категория B (АКПП)</option><option>Категория A</option>
        <option>Восстановление навыков</option><option>Прикрепление для сдачи в ГИБДД</option><option>Подарочный сертификат</option>
      </datalist>
    </label>
    <div class="grid2">
      <label class="field"><span>Удобно</span>
        <select name="contact_time">
          <?php foreach (Source::CONTACT_TIMES as $key => $label): ?>
            <option value="<?= $key ?>"<?= ($old['contact_time'] ?? '') === $key ? ' selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Связь</span>
        <select name="channel">
          <?php foreach (Source::CHANNELS as $key => $label): ?>
            <option value="<?= $key ?>"<?= ($old['channel'] ?? '') === $key ? ' selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="field"><span>Комментарий</span><textarea name="comment" rows="3" maxlength="2000"><?= $value('comment') ?></textarea></label>
    <?php if (\App\Sms\Sms::enabled()): ?>
    <label class="check"><input type="checkbox" name="send_sms"<?= isset($old['send_sms']) ? ' checked' : '' ?>> Отправить клиенту SMS «Заявка принята»</label>
    <?php endif; ?>
    <button class="btn btn--primary btn--wide">Добавить заявку</button>
  </form>
</section>
