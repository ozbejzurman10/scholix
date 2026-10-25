<?php
require_once "funkcije.php";

$user = getUser();
if ($user) {
    redirect("nadzorna_plosca.php");
}
?>

<!DOCTYPE html>
<html lang="sl">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Spletna učilnica - Scholix</title>

    <link rel="stylesheet" href="stil.css?v=<?= filemtime(__DIR__ . "/stil.css") ?>">

</head>

<body>

<section class="hero hero-full">

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
            <a href="prijava.php" class="btn btn-primary">Prijava v sistem</a>
        </div>

    </div>

</section>

<script src="skripta.js"></script>

</body>

</html>
