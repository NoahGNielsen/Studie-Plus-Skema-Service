<?php /** @var callable $h */ ?>
<h1>Privatliv og sikkerhed</h1>

<section class="card">
    <h2>Hvad vi gemmer</h2>
    <ul>
        <li><strong>Dit Studie+-brugernavn og din adgangskode</strong> — krypteret (XChaCha20-Poly1305). De bruges kun til at logge ind
            i Studie+ og hente dit skema. Login-sessionen hos Studie+ kasseres efter hver hentning, og "husk mig" bruges ikke.</li>
        <li><strong>Dit skema</strong> for 14 dage tilbage og 120 dage frem: fag/titel, tidspunkt, lærere, lokaler, noter, lektier og om timen er aflyst.
            Vi henter og gemmer <em>ikke</em> fravær.</li>
        <li><strong>En hash af dit kalenderlink</strong> — selve linket gemmes ikke, derfor kan vi ikke vise det igen.</li>
        <li><strong>Driftsstatus</strong>: hvornår skemaet sidst blev hentet, en kort fejlkode og den dato, din kalender sidst blev hentet.</li>
        <li>Til at begrænse misbrug gemmer vi i kort tid en tæller knyttet til en hash af din IP-adresse. IP-adressen selv gemmes ikke af tjenesten.</li>
        <li><strong>En driftslog</strong> til fejlsøgning: tidspunkt, side, statuskode, svartid, browser-/app-type, et internt bruger-nummer,
            en forkortet hash af IP-adressen samt antal lektioner og fejlkoder fra hentninger. Loggen indeholder ikke brugernavne,
            adgangskoder, cookies, kalenderlinks eller skemaindhold.</li>
    </ul>
</section>

<section class="card">
    <h2>Hvor længe</h2>
    <ul>
        <li>Indtil du sletter dine data under <a href="/administrer">Administrér</a>.</li>
        <li>Automatisk sletning, hvis din kalender ikke er blevet hentet i 90 dage.</li>
    </ul>
</section>

<section class="card">
    <h2>Det skal du vide</h2>
    <ul>
        <li>Din kalender-app (fx Apple, Google eller Microsoft) henter linket og kan gemme skemaet hos sig.</li>
        <li>Webhotellets server kan registrere adresser i sine adgangslogs. Tjenesten selv logger aldrig links, adgangskoder eller cookies.</li>
        <li>Tjenesten er uofficiel og kan holde op med at virke, hvis Studie+ ændrer sig.</li>
    </ul>
</section>
