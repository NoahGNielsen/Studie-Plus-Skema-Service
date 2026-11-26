<?php /** @var callable $h @var string $content */ ?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>Skema i din kalender</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<header class="site-header">
    <a href="/" class="brand">Skema i din kalender</a>
    <nav><a href="/direkte-login">Direkte login</a> <a href="/administrer">Administrér</a> <a href="/privatliv">Privatliv</a></nav>
</header>
<main>
<?= $content ?>
</main>
<footer class="site-footer">
    <p>Uofficiel tjeneste — ikke tilknyttet Studie+, Uddata+ eller HANSENBERG.</p>
</footer>
</body>
</html>
