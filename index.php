<?php
session_start();
$isLoggedIn = isset($_SESSION["user_id"]);
?>

<!DOCTYPE html>

<html lang="sl">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Spletna učilnica</title>

    <link rel="stylesheet" href="style.css">

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
                <a href="login.php" class="btn btn-primary">Prijava</a>
                <a href="register.php" class="btn btn-outline">Registracija dijaka</a>
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

        <a href="login.php" class="module-card">
            <div class="module-icon">📅</div>
            <h3>Urnik</h3>
            <p>
                Pregled ur po dnevih za vsakega dijaka.
            </p>
        </a>


        <a href="login.php" class="module-card">
            <div class="module-icon">📖</div>
            <h3>Predmeti</h3>
            <p>
                Predmeti, povezani z učitelji in dijaki.
            </p>
        </a>


        <a href="login.php" class="module-card">
            <div class="module-icon">📋</div>
            <h3>Naloge</h3>
            <p>
                Učitelj objavi nalogo, dijak jo odda.
            </p>
        </a>


        <a href="login.php" class="module-card">
            <div class="module-icon">🎓</div>
            <h3>Ocene</h3>
            <p>
                Pregled ocen in šolskega uspeha.
            </p>
        </a>


        <a href="login.php" class="module-card">
            <div class="module-icon">👤</div>
            <h3>Izostanki</h3>
            <p>
                Evidenca odsotnosti dijakov.
            </p>
        </a>


        <a href="login.php" class="module-card">
            <div class="module-icon">🏫</div>
            <h3>Učilnice</h3>
            <p>
                Organizacija predmetov in uporabnikov.
            </p>
        </a>

    </div>

</div>

</section>

<script src="script.js"></script>

</body>

</html>
