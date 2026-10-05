<?php

require_once "functions.php";

if (isset($_SESSION["user_id"])) {
    redirect("dashboard.php");
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

        redirect("dashboard.php");

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

    <link rel="stylesheet" href="style.css">

</head>

<body class="auth-page">

<div class="auth-card">

    <a href="index.php">
        ← Nazaj
    </a>

    <h1>Prijava</h1>

    <p>
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


    <p class="center">

        Še nimate računa?

        <a href="register.php">
            Registracija dijaka
        </a>

    </p>

</div>

<script src="script.js"></script>
</body>

</html>
