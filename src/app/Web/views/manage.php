<?php /** @var callable $h @var string $csrf @var array<string, mixed> $user */
$errorTexts = [
    'auth_failed' => 'Studie+ afviste dine gemte loginoplysninger.',
    'decrypt_failed' => 'Dine gemte loginoplysninger kunne ikke bruges.',
    'studieplus_disabled' => 'Hentning fra Studie+ er midlertidigt slået fra.',
    'gwt_not_verified' => 'Hentning fra Studie+ er ikke færdigopsat endnu.',
    'gwt_mapping_not_verified' => 'Hentning fra Studie+ er ikke færdigopsat endnu.',
];
$lastError = $user['lastError'] ?? null;
?>
<h1>Administrér din kalender</h1>

<?php if (!empty($error)): ?>
    <div class="notice notice--error" role="alert"><?= $h($error) ?></div>
<?php endif; ?>

<section class="card">
    <h2>Status</h2>
    <?php if (($user['status'] ?? '') === 'needs_reauth'): ?>
        <div class="notice notice--warn">
            <strong>Din kalender opdateres ikke lige nu.</strong> Studie+ afviste dine gemte loginoplysninger — typisk fordi du har
            skiftet adgangskode. Gå til <a href="/">forsiden</a>, indtast din nye adgangskode og sæt flueben i
            <em>"Jeg har allerede et kalenderlink og vil beholde det"</em>.
            <br>Vi prøver ikke igen automatisk, så din Studie+-konto ikke bliver spærret.
        </div>
    <?php endif; ?>
    <dl class="status">
        <dt>Sidst opdateret</dt>
        <dd><?= $h($user['lastSuccess'] ?? 'Endnu ikke') ?></dd>
        <dt>Seneste forsøg</dt>
        <dd><?= $h($user['lastAttempt'] ?? '–') ?></dd>
        <?php if ($lastError !== null): ?>
            <dt>Seneste fejl</dt>
            <dd><?= $h($errorTexts[$lastError] ?? 'Studie+ kunne ikke nås. Vi prøver igen automatisk, og din kalender viser imens det senest hentede skema.') ?></dd>
        <?php endif; ?>
    </dl>
</section>

<section class="card">
    <h2>Nyt kalenderlink</h2>
    <p>Har du mistet dit link, eller tror du, at andre har fået fat i det? Lav et nyt. Det gamle holder straks op med at virke.</p>
    <form method="post" action="/administrer/nyt-link">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <button type="submit" class="button button--secondary">Lav nyt link</button>
    </form>
</section>

<section class="card card--danger">
    <h2>Slet mine data</h2>
    <p>Sletter dine krypterede loginoplysninger, dit gemte skema og dit kalenderlink. Det kan ikke fortrydes.</p>
    <form method="post" action="/administrer/slet">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <label class="check"><input type="checkbox" name="confirm" value="yes" required> <span>Ja, slet alt</span></label>
        <button type="submit" class="button button--danger">Slet mine data</button>
    </form>
</section>

<form method="post" action="/administrer/log-ud">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <button type="submit" class="link-button">Log ud</button>
</form>
