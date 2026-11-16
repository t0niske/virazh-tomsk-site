<?php

use App\Crm\View as V;

/** @var string $title @var string $message */
?>
<section class="panel empty">
  <h1><?= V::e($title) ?></h1>
  <p><?= V::e($message) ?></p>
  <p><a class="btn" href="/crm">К заявкам</a></p>
</section>
