<?php

require_once "functions.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $ime = trim($_POST["ime"]);
    $priimek = trim($_POST["priimek"]);
    $email = trim($_POST["e_posta"]);
    $geslo = $_POST["geslo"];
    $ponovi = $_POST["ponovi_geslo"];


    if ($ime === "" || $priimek === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Vnesite veljavne podatke.";

    } elseif (strlen($geslo) < 6) {

        $error = "Geslo naj ima vsaj 6 znakov.";

    } elseif ($geslo !== $ponovi) {

        $error = "Gesli se ne ujemata.";

    } else {

        try {

            $check = $conn->prepare("SELECT 1 FROM Uporabnik WHERE e_posta = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                throw new RuntimeException("Ta e-poštni naslov je že uporabljen.");
            }

            $stmt = $conn->prepare("INSERT INTO Uporabnik
                (ime, priimek, e_posta, geslo, vloga)
                VALUES (?, ?, ?, ?, 'dijak')"
            );

            $stmt->execute([
                $ime,
                $priimek,
                $email,
                password_hash($geslo, PASSWORD_DEFAULT)
            ]);

            redirect("login.php");

        } catch (Throwable $e) {

            $error = "Ta e-poštni naslov je že uporabljen.";

        }
    }
}

?>

<!DOCTYPE html>

<html lang="sl">

<head>

    <meta charset="UTF-8">

    <title>Registracija - Scholix</title>

    <link rel="stylesheet" href="style.css">

</head>

<body class="auth-page">

<div class="auth-card auth-card-wide">

    <a href="index.php" class="back-link">
        ← Nazaj
    </a>

    <h1>Registracija dijaka</h1>


    <?php if ($error): ?>

        <div class="error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <form method="POST" class="auth-form">

        <label>
            Ime

            <input
                type="text"
                name="ime"
                required
            >

        </label>


        <label>
            Priimek

            <input
                type="text"
                name="priimek"
                required
            >

        </label>


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

            <div class="password-wrap"><input type="password" name="geslo" id="reg_password" required minlength="6"><button type="button" class="password-toggle" data-target="reg_password">Prikaži</button></div>

        </label>


        <label>
            Ponovi geslo

            <div class="password-wrap"><input type="password" name="ponovi_geslo" id="password_confirm" required minlength="6"><button type="button" class="password-toggle" data-target="password_confirm">Prikaži</button></div>

        </label>


        <button class="btn blue">
            Registracija
        </button>

    </form>

</div>

</body>

<script src="script.js"></script>

</html>
