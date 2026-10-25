<?php

require_once "funkcije.php";

if (isset($_SESSION["user_id"])) {
    redirect("nadzorna_plosca.php");
}

$error = "";
$success = "";

// Pridobi oddelke za izbiro
$oddelki = $conn->query("SELECT * FROM Oddelek ORDER BY letnik, ime")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $ime = trim($_POST["ime"] ?? "");
        $priimek = trim($_POST["priimek"] ?? "");
        $email = trim($_POST["e_posta"] ?? "");
        $geslo = $_POST["geslo"] ?? "";
        $geslo2 = $_POST["geslo_potrditev"] ?? "";
        $oddelekId = !empty($_POST["oddelek_id"]) ? (int)$_POST["oddelek_id"] : null;

        if ($ime === "" || $priimek === "" || $email === "" || $geslo === "") {
            throw new RuntimeException("Prosimo, izpolnite vsa obvezna polja.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Vnesite veljaven e-poštni naslov.");
        }

        if (strlen($geslo) < 6) {
            throw new RuntimeException("Geslo mora vsebovati vsaj 6 znakov.");
        }

        if ($geslo !== $geslo2) {
            throw new RuntimeException("Gesli se ne ujemata.");
        }

        // Preveri, ali e-pošta že obstaja
        $check = $conn->prepare("SELECT 1 FROM Uporabnik WHERE e_posta = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new RuntimeException("Uporabnik s tem e-poštnim naslovom je že registriran.");
        }

        // Vstavi novega dijaka
        $hash = password_hash($geslo, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "INSERT INTO Uporabnik (ime, priimek, e_posta, geslo, vloga, oddelek_id)
             VALUES (?, ?, ?, ?, 'dijak', ?)"
        );
        $stmt->execute([$ime, $priimek, $email, $hash, $oddelekId]);

        $novUporabnikId = $conn->lastInsertId();

        // Samodejno vpiši dijaka na obvezne predmete njegovega letnika/oddelka, če je oddelek izbran
        if ($oddelekId) {
            // Predmeti, ki se izvajajo za ta oddelek v urniku
            $predmetiOddelka = $conn->prepare(
                "SELECT DISTINCT predmet_id FROM Urnik WHERE oddelek_id = ?"
            );
            $predmetiOddelka->execute([$oddelekId]);
            $po = $predmetiOddelka->fetchAll(PDO::FETCH_COLUMN);

            $ins = $conn->prepare("INSERT IGNORE INTO Dijak_Predmet (dijak_id, predmet_id) VALUES (?, ?)");
            foreach ($po as $pid) {
                $ins->execute([$novUporabnikId, $pid]);
            }
        }

        flash("Registracija je bila uspešna! Sedaj se lahko prijavite s svojimi podatki.");
        redirect("prijava.php");

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <title>Registracija dijaka - Scholix</title>
    <link rel="stylesheet" href="stil.css?v=<?= filemtime(__DIR__ . "/stil.css") ?>">
</head>
<body class="auth-page">

<div class="auth-card">

    <a href="prijava.php" class="back-link" aria-label="Nazaj">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </a>

    <h1>Registracija dijaka</h1>

    <p class="auth-subtitle">
        Ustvarite svoj račun za dostop do šolskih gradiv in nalog.
    </p>

    <?php if ($error): ?>
        <div class="error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="auth-form">

        <div class="form-row-2">
            <label>
                Ime
                <input type="text" name="ime" value="<?= e($_POST["ime"] ?? "") ?>" required>
            </label>

            <label>
                Priimek
                <input type="text" name="priimek" value="<?= e($_POST["priimek"] ?? "") ?>" required>
            </label>
        </div>

        <label>
            E-pošta
            <input type="email" name="e_posta" value="<?= e($_POST["e_posta"] ?? "") ?>" placeholder="ime.priimek@sc-celje.si" required>
        </label>

        <label>
            Razred / Oddelek
            <select name="oddelek_id" required>
                <option value="">-- Izberite svoj razred --</option>
                <?php foreach ($oddelki as $o): ?>
                    <option value="<?= (int)$o["id_oddelek"] ?>" <?= (isset($_POST["oddelek_id"]) && (int)$_POST["oddelek_id"] === (int)$o["id_oddelek"]) ? "selected" : "" ?>>
                        <?= e($o["ime"]) ?> (<?= (int)$o["letnik"] ?>. letnik - <?= e($o["program"]) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Geslo
            <div class="password-wrap">
                <input type="password" name="geslo" id="reg_pass" minlength="6" placeholder="Najmanj 6 znakov" required>
                <button type="button" class="password-toggle" data-target="reg_pass">Prikaži</button>
            </div>
        </label>

        <label>
            Ponovitev gesla
            <div class="password-wrap">
                <input type="password" name="geslo_potrditev" id="reg_pass2" minlength="6" placeholder="Ponovite geslo" required>
                <button type="button" class="password-toggle" data-target="reg_pass2">Prikaži</button>
            </div>
        </label>

        <button class="btn blue btn-block mt-2">
            Registriraj se
        </button>

    </form>

    <p class="auth-footer">
        Že imate račun? <a href="prijava.php" class="link-bold">Prijavite se tukaj</a>.
    </p>

</div>

<script src="skripta.js"></script>
</body>
</html>
