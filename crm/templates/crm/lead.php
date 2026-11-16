<?php

use App\Crm\Auth;
use App\Crm\View as V;
use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Schedule;
use App\Leads\Source;
use App\Leads\Status;

/**
 * @var list<array> $visits заходы на сайт этого клиента
 * @var array $lead @var list<array> $events @var list<array> $chat @var ?array $siteChat @var list<array> $staffList @var ?string $staffName
 * @var string $statusLine @var list<string> $reasons @var list<array> $sms
 */
$id = (int) $lead['id'];
$closed = Status::isClosed($lead['status']);
$inMax = ($lead['max_user_id'] ?? '') !== '';
$action = static fn (string $name): string => '/crm/lead/' . $id . '/' . $name;
$utm = array_filter([
    'utm_source' => $lead['utm_source'], 'utm_medium' => $lead['utm_medium'], 'utm_campaign' => $lead['utm_campaign'],
    'utm_content' => $lead['utm_content'], 'utm_term' => $lead['utm_term'], 'yclid' => $lead['yclid'],
]);
$defaultAt = Schedule::fromSettings()->nextOpen(Clock::now()->modify('+1 hour'))->format('Y-m-d\TH:i');
?>
<a class="back link" href="/crm">← Все заявки</a>

<section class="lead-head panel">
  <div class="lead-head__title">
    <h1>№<?= $id ?> · <?= V::e($lead['name']) ?></h1>
    <div><?= V::status($lead) ?></div>
  </div>
  <a class="phone-btn" href="tel:<?= V::e($lead['phone']) ?>">📞 <?= V::phone($lead['phone']) ?></a>
  <p class="status-line"><?= nl2br(V::e($statusLine)) ?></p>

  <div class="actions">
    <?php if (!$closed): ?>
      <?php if ($lead['status'] === Status::NEW): ?>
        <form method="post" action="<?= $action('take') ?>"><?= V::csrf() ?><button class="btn btn--primary">Беру</button></form>
      <?php endif; ?>
      <form method="post" action="<?= $action('miss') ?>"><?= V::csrf() ?><button class="btn">Не дозвонился</button></form>
      <form method="post" action="<?= $action('status') ?>"><?= V::csrf() ?><input type="hidden" name="status" value="talked"><button class="btn">Думает</button></form>
      <form method="post" action="<?= $action('status') ?>"><?= V::csrf() ?><input type="hidden" name="status" value="contract"><button class="btn">Придёт на договор</button></form>
      <form method="post" action="<?= $action('status') ?>"><?= V::csrf() ?><input type="hidden" name="status" value="enrolled"><button class="btn btn--ok">Записан</button></form>
      <details class="dropdown">
        <summary class="btn">Отказ</summary>
        <form class="dropdown__body" method="post" action="<?= $action('status') ?>">
          <?= V::csrf() ?>
          <input type="hidden" name="status" value="refused">
          <label class="field"><span>Причина</span>
            <select name="reason">
              <?php foreach ($reasons as $reason): ?><option><?= V::e($reason) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Или своими словами</span><input name="reason_other" maxlength="150"></label>
          <button class="btn btn--danger">Записать отказ</button>
        </form>
      </details>
      <form method="post" action="<?= $action('status') ?>" data-confirm="Отметить заявку как мусорную (тест, бот, неверный номер)?"><?= V::csrf() ?><input type="hidden" name="status" value="junk"><button class="btn btn--ghost">Мусор</button></form>
    <?php else: ?>
      <form method="post" action="<?= $action('status') ?>"><?= V::csrf() ?><input type="hidden" name="status" value="new"><button class="btn">Вернуть в работу</button></form>
    <?php endif; ?>
  </div>
</section>

<div class="lead-grid">
  <div class="lead-main">
    <?php if ($inMax || $chat !== []): ?>
      <section class="panel" id="chat">
        <h2>Переписка в MAX</h2>
        <div class="chat" data-chat>
          <?php if ($chat === []): ?>
            <p class="muted">Клиент открыл бота, сообщений пока нет.</p>
          <?php endif; ?>
          <?php foreach ($chat as $m): ?>
            <div class="msg msg--<?= $m['type'] === 'max_in' ? 'in' : 'out' ?><?= $m['type'] === 'max_bot' ? ' msg--bot' : '' ?>">
              <div class="msg__text"><?= V::e($m['text']) ?></div>
              <div class="msg__meta"><?= V::e($m['type'] === 'max_in' ? $lead['name'] : ($m['type'] === 'max_bot' ? 'Бот' : $m['actor'])) ?> · <?= V::date($m['created_at']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($inMax): ?>
          <form class="reply" method="post" action="<?= $action('reply') ?>">
            <?= V::csrf() ?>
            <label class="sr" for="reply">Ответ клиенту</label>
            <textarea id="reply" name="text" rows="2" maxlength="4000" placeholder="Ответ клиенту от имени автошколы" required></textarea>
            <button class="btn btn--primary">Отправить</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="panel">
      <h2>История</h2>
      <form class="reply" method="post" action="<?= $action('comment') ?>">
        <?= V::csrf() ?>
        <label class="sr" for="comment">Комментарий</label>
        <textarea id="comment" name="text" rows="2" maxlength="2000" placeholder="Комментарий: о чём договорились, что важно" required></textarea>
        <button class="btn">Добавить</button>
      </form>
      <ol class="timeline">
        <?php foreach ($events as $e): ?>
          <li class="timeline__item timeline__item--<?= V::e($e['type']) ?>">
            <time><?= V::date($e['created_at']) ?></time>
            <div>
              <strong><?= V::e(Leads::EVENT_LABELS[$e['type']] ?? $e['type']) ?></strong><?= $e['actor'] && !in_array($e['type'], ['created', 'repeat', 'max_in'], true) ? ' · ' . V::e($e['actor']) : '' ?>
              <?php if ($e['text'] !== null && $e['text'] !== ''): ?><div class="timeline__text"><?= V::e($e['text']) ?></div><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  </div>

  <aside class="lead-side">
    <section class="panel">
      <h2>Клиент</h2>
      <dl class="props">
        <dt>Телефон</dt><dd><a href="tel:<?= V::e($lead['phone']) ?>"><?= V::phone($lead['phone']) ?></a></dd>
        <dt>Услуга</dt><dd><?= V::e($lead['service'] ?: '—') ?></dd>
        <dt>Удобно</dt><dd><?= V::e(Source::CONTACT_TIMES[$lead['contact_time']] ?? '') ?></dd>
        <dt>Связь</dt><dd><?= V::e(Source::CHANNELS[$lead['channel']] ?? '') ?><?= $inMax ? ' · <span class="badge badge--chat">в MAX</span>' : '' ?></dd>
        <dt>Поступила</dt><dd><?= V::date($lead['created_at']) ?><?= (int) $lead['off_hours'] ? ' · ночью/в выходной' : '' ?></dd>
        <dt>Откуда</dt><dd><?= V::e($lead['source']) ?></dd>
        <?php foreach ($utm as $key => $value): ?>
          <dt class="muted"><?= $key ?></dt><dd class="muted small"><?= V::e($value) ?></dd>
        <?php endforeach; ?>
        <?php if ($lead['page_url']): ?>
          <dt>Страница</dt><dd class="small break"><?= V::e($lead['page_url']) ?></dd>
        <?php endif; ?>
        <?php if ((int) $lead['request_count'] > 1): ?>
          <dt>Заявок</dt><dd><?= (int) $lead['request_count'] ?>, последняя <?= V::date($lead['last_request_at']) ?></dd>
        <?php endif; ?>
      </dl>
    </section>

    <?php if (!empty($siteChat)): ?>
      <section class="panel">
        <h2>Вопрос из чата</h2>
        <p class="small">Перед заявкой посетитель переписывался с ботом на сайте, реплик: <?= (int) $siteChat['messages'] ?>.</p>
        <a class="btn btn--wide" href="/crm/chat/<?= (int) $siteChat['id'] ?>">Открыть переписку</a>
      </section>
    <?php endif; ?>

    <section class="panel">
      <h2>Путь клиента</h2>
      <?php if ($visits === []): ?>
        <p class="muted small">Заходов на сайт с этой заявкой не связано: заявка пришла не с сайта, до подключения счётчика или cookie заблокированы.</p>
      <?php else: ?>
        <?php
          $first = $visits[0];
          $days = (int) floor((strtotime($lead['created_at']) - strtotime($first['started_at'])) / 86400);
        ?>
        <p class="small">Первый заход: <?= V::e($first['source']) ?>, <?= V::date($first['started_at']) ?>.
          <?= $days > 0 ? 'До заявки ' . $days . ' дн.' : 'Заявка в тот же день.' ?> Всего заходов: <?= count($visits) ?>.</p>
        <ul class="plain">
          <?php foreach (array_slice(array_reverse($visits), 0, 10) as $v): ?>
            <li class="small">
              <a href="/crm/visit/<?= $v['id'] ?>"><?= V::date($v['started_at']) ?></a> · <?= V::e($v['source']) ?> · <?= (int) $v['pages'] ?> стр., <?= V::duration((int) $v['active_seconds']) ?>
              <?= (int) $v['has_tel'] ? ' · ☎' : '' ?><?= $v['lead_id'] !== null && (int) $v['lead_id'] === $id ? ' · <strong>заявка</strong>' : '' ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if (!$closed): ?>
      <section class="panel">
        <h2>Ответственный</h2>
        <form class="inline" method="post" action="<?= $action('assign') ?>">
          <?= V::csrf() ?>
          <select name="staff_id" aria-label="Ответственный">
            <option value="0">не назначен</option>
            <?php foreach ($staffList as $s): ?>
              <option value="<?= $s['id'] ?>"<?= (int) $lead['assigned_to'] === (int) $s['id'] ? ' selected' : '' ?>><?= V::e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn">Сохранить</button>
        </form>
      </section>

      <section class="panel">
        <h2>Запланировать</h2>
        <form method="post" action="<?= $action('schedule') ?>">
          <?= V::csrf() ?>
          <div class="inline">
            <select name="kind" aria-label="Что сделать">
              <option value="call">Позвонить</option>
              <?php if ($inMax): ?><option value="message">Написать в MAX</option><?php endif; ?>
            </select>
            <input type="datetime-local" name="at" value="<?= $defaultAt ?>" required aria-label="Когда">
          </div>
          <button class="btn btn--wide">Напомнить</button>
          <p class="muted small">В это время заявка появится в «Кому звонить», а бот напомнит в чат.</p>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($sms !== []): ?>
      <section class="panel">
        <h2>SMS</h2>
        <ul class="plain">
          <?php foreach ($sms as $s): ?>
            <li><span class="muted small"><?= V::date($s['created_at']) ?><?= $s['status'] !== 'sent' ? ' · не отправлено' : '' ?></span><br><?= V::e($s['text']) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <details class="panel">
      <summary><h2>Исправить данные</h2></summary>
      <form method="post" action="<?= $action('edit') ?>">
        <?= V::csrf() ?>
        <label class="field"><span>Имя</span><input name="name" value="<?= V::e($lead['name']) ?>" maxlength="100" required></label>
        <label class="field"><span>Телефон</span><input name="phone" value="<?= V::e(V::phone($lead['phone'])) ?>" inputmode="tel" required></label>
        <label class="field"><span>Услуга</span><input name="service" value="<?= V::e($lead['service']) ?>" maxlength="150"></label>
        <button class="btn">Сохранить</button>
      </form>
      <?php if (Auth::isManager()): ?>
        <form method="post" action="<?= $action('delete') ?>" data-confirm="Удалить заявку №<?= $id ?> со всей историей? Это нельзя отменить.">
          <?= V::csrf() ?>
          <button class="btn btn--danger btn--wide">Удалить заявку</button>
        </form>
      <?php endif; ?>
    </details>
  </aside>
</div>
