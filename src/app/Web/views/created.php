<?php /** @var callable $h @var string $feedUrl @var string $host */
$webcalUrl = preg_replace('#^https://#', 'webcal://', $feedUrl);
?>
<h1><?= empty($rotated) ? 'Din kalender er klar' : 'Du har fået et nyt kalenderlink' ?></h1>

<?php if (!empty($rotated)): ?>
    <div class="notice notice--warn">Dit gamle link virker ikke længere. Fjern den gamle kalender i din kalender-app og tilføj det nye link.</div>
<?php endif; ?>

<section class="card">
    <h2>Dit personlige kalenderlink</h2>
    <p><strong>Gem linket nu.</strong> Af sikkerhedshensyn kan vi ikke vise det igen. Mister du det, kan du lave et nyt under Administrér.</p>
    <div class="copy-row">
        <input id="feed-url" class="copy-input" type="text" readonly value="<?= $h($feedUrl) ?>" aria-label="Kalenderlink">
        <button type="button" class="button" data-copy="feed-url">Kopiér</button>
    </div>
    <p class="copy-status" id="copy-status" aria-live="polite"></p>
    <p><a class="button button--secondary" href="<?= $h($webcalUrl) ?>">Åbn i kalender-app</a></p>
</section>

<section class="card">
    <h2>Sådan tilføjer du kalenderen</h2>
    <details open>
        <summary>iPhone / iPad</summary>
        <p>Tryk på <em>Åbn i kalender-app</em> ovenfor og vælg <em>Abonnér</em>. Eller: Indstillinger → Kalender → Konti →
            Tilføj konto → Andet → Tilføj abonnementskalender, og indsæt linket.</p>
    </details>
    <details>
        <summary>Google Kalender (også Android)</summary>
        <p>Åbn Google Kalender → <em>Andre kalendere</em> → <em>+</em> → <em>Fra webadresse</em>, og indsæt linket.</p>
    </details>
    <details>
        <summary>Outlook</summary>
        <p>Outlook på nettet → Kalender → <em>Tilføj kalender</em> → <em>Abonner fra internettet</em>, og indsæt linket.</p>
    </details>
    <p class="hint">Vi henter dit skema fra Studie+ hvert 15. minut på hverdage kl. 06:30–16:00 og ellers én gang i timen.
        Din kalender-app bestemmer selv, hvor tit den henter fra os.</p>
</section>
