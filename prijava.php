<?php

require_once "funkcije.php";

// Strani, na katere lahko začetna stran preusmeri po prijavi
$dovoljeneStrani = ["nadzorna_plosca.php", "naloge.php", "urnik.php", "govorilne.php", "ocene.php", "izostanki.php", "profil.php"];
$next = $_GET["next"] ?? "";
$cilj = in_array($next, $dovoljeneStrani, true) ? $next : "nadzorna_plosca.php";

if (isset($_SESSION["user_id"])) {
    redirect($cilj);
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["e_posta"]);
    $password = $_POST["geslo"];

    $stmt = $conn->prepare(
        "SELECT * FROM Uporabnik WHERE e_posta = ?"
    );

    $stmt->execute([$email]);

    $user = $stmt->fetch();

    if ($user && password_verify($password, $user["geslo"])) {

        session_regenerate_id(true);

        $_SESSION["user_id"] = $user["id_uporabnik"];
        $_SESSION["role"] = $user["vloga"];

        redirect($cilj);

    } else {

        $error = "Napačen e-poštni naslov ali geslo.";

    }
}

?>

<!DOCTYPE html>

<html lang="sl">

<head>

    <meta charset="UTF-8">

    <title>Prijava - Scholix</title>

    <link rel="stylesheet" href="stil.css?v=<?= filemtime(__DIR__ . "/stil.css") ?>">

</head>

<body class="auth-page">

<div class="auth-card">

    <a href="index.php" class="back-link" aria-label="Nazaj">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </a>

    <h1>Prijava</h1>

    <p class="auth-subtitle">
        Prijavite se v spletno učilnico.
    </p>


    <?php if ($error): ?>

        <div class="error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <form method="POST" class="auth-form">

        <label>
            E-pošta

            <input
                type="email"
                name="e_posta"
                required
            >

        </label>


        <label>
            Geslo

            <div class="password-wrap"><input type="password" name="geslo" id="login_password" required><button type="button" class="password-toggle" data-target="login_password">Prikaži</button></div>

        </label>


        <button class="btn blue">
            Prijava
        </button>

    </form>


    <p class="auth-footer">
        Še nimate računa? <a href="registracija.php" class="link-bold">Registrirajte se kot dijak</a>.
    </p>

</div>

<script src="skripta.js"></script>
</body>

</html>
