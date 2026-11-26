<?php /** @var callable $h @var string $csrf */ ?>
<h1>Administrér din kalender</h1>
<p>Log ind med det Studie+-brugernavn og den adgangskode, du brugte, da du oprettede kalenderen.
    Herefter kan du lave et nyt link eller slette dine data.</p>

<?php if (!empty($error)): ?>
    <div class="notice notice--error" role="alert"><?= $h($error) ?></div>
<?php endif; ?>

<form class="card form" method="post" action="/administrer/log-ind" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <label for="username">Studie+-brugernavn</label>
    <input id="username" name="username" type="text" required maxlength="100" autocapitalize="none" spellcheck="false">
    <label for="password">Studie+-adgangskode</label>
    <input id="password" name="password" type="password" required maxlength="200" autocomplete="current-password">
    <button type="submit" class="button">Fortsæt</button>
    <p class="hint">Du logges automatisk ud efter 15 minutter uden aktivitet.</p>
</form>
