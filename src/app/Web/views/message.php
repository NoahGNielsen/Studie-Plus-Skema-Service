<?php /** @var callable $h @var string $title @var string $text */ ?>
<h1><?= $h($title) ?></h1>
<section class="card">
    <p><?= $h($text) ?></p>
    <p><a class="button button--secondary" href="/">Til forsiden</a></p>
</section>
