<?php /** @var callable $h @var string $csrf */
// Delt popup: opdatér loginoplysninger og behold det eksisterende kalenderlink.
$keepError = $keepError ?? null;
?>
<button type="button" class="button button--secondary" data-dialog-open="keep-link-dialog">Jeg har skiftet adgangskode i Studie+</button>

<dialog id="keep-link-dialog" class="dialog" aria-labelledby="keep-link-title"<?= $keepError !== null ? ' data-open-on-load' : '' ?>>
    <form class="form" method="post" action="/opret" autocomplete="off" data-loading>
        <h2 id="keep-link-title">Behold dit kalenderlink</h2>
        <p>Har du skiftet adgangskode i Studie+? Indtast dit brugernavn og din <strong>nye</strong> adgangskode.
            Så opdaterer vi dine gemte oplysninger, og dit nuværende kalenderlink bliver ved med at virke.</p>

        <?php if ($keepError !== null): ?>
            <div class="notice notice--error" role="alert"><?= $h($keepError) ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="keep_link" value="yes">

        <label for="keep-username">Studie+-brugernavn</label>
        <input id="keep-username" name="username" type="text" required maxlength="100" autocapitalize="none" spellcheck="false"
               value="<?= $h($keepUsername ?? '') ?>">

        <label for="keep-password">Ny Studie+-adgangskode</label>
        <input id="keep-password" name="password" type="password" required maxlength="200" autocomplete="current-password">

        <label class="check">
            <input type="checkbox" name="consent" value="yes" required>
            <span>Jeg accepterer, at mine Studie+-loginoplysninger gemmes krypteret og bruges til at hente mit skema automatisk,
                som beskrevet under <a href="/privatliv">Privatliv</a>.</span>
        </label>

        <button type="submit" class="button">Opdatér og behold link</button>
        <p class="hint" data-loading-text role="status" aria-live="polite" hidden>Vi tjekker dit login … Det kan tage op til 2 minutter. Luk ikke siden.</p>
        <button type="button" class="link-button" data-dialog-close>Annullér</button>
    </form>
</dialog>
