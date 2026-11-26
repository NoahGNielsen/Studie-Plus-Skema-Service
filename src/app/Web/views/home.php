<?php /** @var callable $h @var string $csrf @var bool $enabled */ ?>
<h1>Få dit Studie+-skema i din kalender</h1>
<p class="lead">Dit skema fra Studie+ (HANSENBERG) bliver til en kalender, du kan abonnere på i din telefon eller på din computer.
    Den opdateres automatisk.</p>

<?php if (!$enabled): ?>
    <div class="notice notice--warn"><strong>Tjenesten er ikke aktiveret endnu.</strong> Prøv igen senere.</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="notice notice--error" role="alert"><?= $h($error) ?></div>
<?php endif; ?>

<section class="card">
    <h2>Før du starter</h2>
    <ul>
        <li>Det virker kun, hvis du logger ind i Studie+ med <strong>"Direkte"</strong> (brugernavn og adgangskode til Studie+).
            Logger du normalt ind med UNI-Login eller MitID, skal du først oprette et Direkte login —
            <a href="/direkte-login">se hvordan her</a>.</li>
        <li>Vi gemmer dit brugernavn og din adgangskode <strong>krypteret</strong>, så skemaet kan hentes automatisk.
            Du kan til enhver tid slette dem under <a href="/administrer">Administrér</a>.</li>
        <li>Brug ikke den samme adgangskode andre steder. Læs mere under <a href="/privatliv">Privatliv</a>.</li>
    </ul>
</section>

<form class="card form" method="post" action="/opret" autocomplete="off" data-loading>
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <label for="username">Studie+-brugernavn</label>
    <input id="username" name="username" type="text" required maxlength="100" autocapitalize="none" spellcheck="false"
           value="<?= $h($username ?? '') ?>" <?= $enabled ? '' : 'disabled' ?>>

    <label for="password">Studie+-adgangskode</label>
    <input id="password" name="password" type="password" required maxlength="200" autocomplete="current-password" <?= $enabled ? '' : 'disabled' ?>>

    <label class="check">
        <input type="checkbox" name="consent" value="yes" required>
        <span>Jeg accepterer, at mine Studie+-loginoplysninger gemmes krypteret og bruges til at hente mit skema automatisk,
            som beskrevet under <a href="/privatliv">Privatliv</a>.</span>
    </label>

    <button type="submit" class="button" <?= $enabled ? '' : 'disabled' ?>>Opret min kalender</button>
    <p class="hint" data-loading-text role="status" aria-live="polite" hidden>Vi henter dit skema … Det kan tage op til 2 minutter, mens vi tjekker dit login og henter dit skema. Luk ikke siden.</p>
</form>

<p class="hint">Har du allerede en kalender og har skiftet adgangskode i Studie+? Så kan du beholde dit link under <a href="/administrer">Administrér</a>.</p>
