<?php
session_start();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = 'Prijava je pripravljena za povezavo z vašo bazo.';
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prijava | Spletna učilnica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <a class="back-link" href="index.php">← Nazaj na začetno stran</a>
        <div class="auth-logo">SU</div>
        <h1>Prijava</h1>
        <p class="auth-subtitle">Prijavite se v svojo spletno učilnico.</p>

        <?php if ($message): ?>
            <div class="form-message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <label for="email">E-poštni naslov</label>
            <input id="email" name="email" type="email" placeholder="ime@sola.si" required>

            <label for="password">Geslo</label>
            <div class="password-wrap">
                <input id="password" name="password" type="password" placeholder="Vnesite geslo" required>
                <button type="button" class="password-toggle" data-target="password">Prikaži</button>
            </div>

            <button class="btn btn-primary full-width" type="submit">Prijava</button>
        </form>

        <p class="auth-footer">Še nimate računa? <a href="register.php">Registracija učitelja</a></p>
    </div>
    <script src="script.js"></script>
</body>
</html>
