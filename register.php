<?php
session_start();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = 'Registracija je pripravljena za povezavo z vašo bazo.';
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registracija učitelja | Spletna učilnica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <div class="auth-card auth-card-wide">
        <a class="back-link" href="index.php">← Nazaj na začetno stran</a>
        <div class="auth-logo">SU</div>
        <h1>Registracija učitelja</h1>
        <p class="auth-subtitle">Ustvarite račun za upravljanje pouka.</p>

        <?php if ($message): ?>
            <div class="form-message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form two-columns">
            <div>
                <label for="first_name">Ime</label>
                <input id="first_name" name="first_name" type="text" placeholder="Janez" required>
            </div>
            <div>
                <label for="last_name">Priimek</label>
                <input id="last_name" name="last_name" type="text" placeholder="Novak" required>
            </div>
            <div class="span-two">
                <label for="email">E-poštni naslov</label>
                <input id="email" name="email" type="email" placeholder="janez.novak@sola.si" required>
            </div>
            <div>
                <label for="reg_password">Geslo</label>
                <input id="reg_password" name="password" type="password" placeholder="Geslo" minlength="6" required>
            </div>
            <div>
                <label for="password_confirm">Ponovi geslo</label>
                <input id="password_confirm" name="password_confirm" type="password" placeholder="Ponovite geslo" minlength="6" required>
            </div>
            <div class="span-two">
                <button class="btn btn-primary full-width" type="submit">Ustvari račun</button>
            </div>
        </form>

        <p class="auth-footer">Že imate račun? <a href="login.php">Prijavite se</a></p>
    </div>
    <script src="script.js"></script>
</body>
</html>
