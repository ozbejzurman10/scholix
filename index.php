<?php
session_start();
$isLoggedIn = isset($_SESSION["user_id"]);

// Prijavljen uporabnik gre direktno na stran, sicer na prijavo,
// ki ga po uspešni prijavi preusmeri na izbrani zavihek.
$go = function (string $page) use ($isLoggedIn) {
    return $isLoggedIn ? $page : "login.php?next=" . urlencode($page);
};

$svg = function (string $paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
};
?>

<!DOCTYPE html>

<html lang="sl">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Spletna učilnica</title>

    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">

</head>

<body>

<section class="hero">

    <div class="hero-inner">

        <div class="role-badge">
            Za učitelje in dijake
        </div>

        <h1>
            Spletna učilnica
        </h1>

        <p>
            Urnik, predmeti, domače naloge, ocene in izostanki –
            vse na enem mestu, pregledno za vsak razred in vsakega dijaka.
        </p>

        <div class="hero-actions">

            <?php if ($isLoggedIn): ?>
                <a href="dashboard.php" class="btn btn-primary">Odpri učilnico</a>
                <a href="logout.php" class="btn btn-outline">Odjava</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">Prijava v sistem</a>
            <?php endif; ?>

        </div>

    </div>

</section>


<section class="modules-section">

<div class="modules-container">

    <h2>
        Moduli aplikacije
    </h2>


    <div class="module-grid">

        <a href="<?= $go("dashboard.php") ?>" class="module-card">
            <div class="module-icon"><?= $svg('<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>') ?></div>
            <h3>Nadzorna plošča</h3>
            <p>
                Domača stran glede na vlogo: administrator, učitelj ali dijak.
            </p>
        </a>


        <a href="<?= $go("urnik.php") ?>" class="module-card">
            <div class="module-icon"><?= $svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>') ?></div>
            <h3>Urnik</h3>
            <p>
                Pregled ur po dnevih za posamezen razred.
            </p>
        </a>


        <a href="<?= $go("ocene.php") ?>" class="module-card">
            <div class="module-icon"><?= $svg('<path d="M12 15l-3.5 2 1-4L6 10l4-.5L12 6l2 3.5 4 .5-3.5 3 1 4z"/>') ?></div>
            <h3>Ocene</h3>
            <p>
                Pregled ocen po razredih in dijakih.
            </p>
        </a>


        <a href="<?= $go("izostanki.php") ?>" class="module-card">
            <div class="module-icon"><?= $svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>') ?></div>
            <h3>Izostanki</h3>
            <p>
                Evidenca odsotnosti dijakov.
            </p>
        </a>


        <a href="<?= $go("profile.php") ?>" class="module-card">
            <div class="module-icon"><?= $svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>') ?></div>
            <h3>Profil</h3>
            <p>
                Urejanje osebnih podatkov in gesla.
            </p>
        </a>

    </div>

</div>

</section>

<script src="script.js"></script>

</body>

</html>
