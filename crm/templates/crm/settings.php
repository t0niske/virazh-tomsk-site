<?php

use App\Crm\View as V;
use App\Leads\Settings;

/** @var array $fields @var array $defaults */
?>
<div class="head">
  <h1>Настройки</h1>
</div>
<p class="muted">Пустое поле — вернуть значение по умолчанию. В текстах можно использовать подстановки в фигурных скобках, они показаны под полем.
  В автоматических сообщениях клиентам не должно быть рекламы: акций, скидок, промокодов.</p>

<form method="post" action="/crm/settings" class="settings">
  <?= V::csrf() ?>
  <?php foreach ($fields as $group => $items): ?>
    <details class="panel"<?= $group === array_key_first($fields) ? ' open' : '' ?>>
      <summary><h2><?= V::e($group) ?></h2></summary>
      <?php foreach ($items as $key => [$label, $type, $hint]): ?>
        <?php $value = Settings::get($key); $custom = Settings::isCustom($key); ?>
        <label class="field<?= $type === 'time' || $type === 'number' ? ' field--short' : '' ?>">
          <span><?= V::e($label) ?><?= $custom ? ' <em class="tag">изменено</em>' : '' ?></span>
          <?php if ($type === 'textarea' || $type === 'sms'): ?>
            <textarea name="<?= $key ?>" rows="<?= $type === 'sms' ? 2 : max(3, min(9, substr_count($value, "\n") + 2)) ?>"<?= $type === 'sms' ? ' data-sms' : '' ?>><?= V::e($value) ?></textarea>
          <?php else: ?>
            <input name="<?= $key ?>" value="<?= V::e($value) ?>"<?= $type === 'number' ? ' inputmode="numeric"' : '' ?><?= $type === 'time' ? ' placeholder="10:00"' : '' ?>>
          <?php endif; ?>
          <?php if ($hint !== '' || $type === 'sms'): ?>
            <small class="muted"><?= V::e($hint) ?><?= $type === 'sms' ? ' <b data-count></b>' : '' ?></small>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </details>
  <?php endforeach; ?>
  <div class="sticky-save"><button class="btn btn--primary">Сохранить настройки</button></div>
</form>
