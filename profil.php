<?php

require_once "funkcije.php";

requireLogin();

$user = getUser();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $ime = trim($_POST["ime"]);
        $priimek = trim($_POST["priimek"]);
        $email = trim($_POST["e_posta"]);

        if ($ime === "" || $priimek === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Vnesite veljavne podatke.");
        }

        // Preveri edinstvenost e-pošte
        $check = $conn->prepare("SELECT 1 FROM Uporabnik WHERE e_posta = ? AND id_uporabnik != ?");
        $check->execute([$email, $user["id_uporabnik"]]);
        if ($check->fetch()) {
            throw new RuntimeException("Ta e-poštni naslov je že uporabljen.");
        }

        $stmt = $conn->prepare(
            "UPDATE Uporabnik
             SET ime = ?, priimek = ?, e_posta = ?
             WHERE id_uporabnik = ?"
        );

        $stmt->execute([
            $ime,
            $priimek,
            $email,
            $user["id_uporabnik"]
        ]);


        if (!empty($_POST["geslo"])) {

            if (empty($_POST["staro_geslo"]) || !password_verify($_POST["staro_geslo"], $user["geslo"])) {
                throw new RuntimeException("Za spremembo gesla vpišite pravilno staro geslo.");
            }

            if ($_POST["geslo"] !== ($_POST["ponovi_geslo"] ?? "")) {
                throw new RuntimeException("Novi gesli se ne ujemata.");
            }

            if (strlen($_POST["geslo"]) < 6) {
                throw new RuntimeException("Novo geslo mora imeti vsaj 6 znakov.");
            }

            $stmt = $conn->prepare(
                "UPDATE Uporabnik
                 SET geslo = ?
                 WHERE id_uporabnik = ?"
            );

            $stmt->execute([
                password_hash($_POST["geslo"], PASSWORD_DEFAULT),
                $user["id_uporabnik"]
            ]);
        }


        flash("Profil je bil uspešno posodobljen.");

        redirect("profil.php");

    } catch (Throwable $e) {

        $error = $e instanceof PDOException ? "E-poštni naslov je morda že uporabljen." : $e->getMessage();

    }
}

pageStart("Moj profil");

?>

<?php
$vlogeImena = ["admin" => "Administrator", "ucitelj" => "Učitelj", "dijak" => "Dijak"];
$vlogaLabel = $vlogeImena[$user["vloga"]] ?? $user["vloga"];

$oddelekIme = "";
$razrednikIme = "";
if (!empty($user["oddelek_id"])) {
    $stmtOdd = $conn->prepare("
        SELECT o.ime AS oddelek_ime, r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
        FROM Oddelek o
        LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
        WHERE o.id_oddelek = ?
    ");
    $stmtOdd->execute([$user["oddelek_id"]]);
    $oddRow = $stmtOdd->fetch();
    if ($oddRow) {
        $oddelekIme = (string) $oddRow["oddelek_ime"];
        if (!empty($oddRow["razrednik_ime"])) {
            $razrednikIme = trim($oddRow["razrednik_priimek"] . " " . $oddRow["razrednik_ime"]);
        }
    }
}

$uciteljRazrednistva = [];
if ($user["vloga"] === "ucitelj") {
    $stmtUr = $conn->prepare("SELECT ime FROM Oddelek WHERE razrednik_id = ? ORDER BY letnik, ime");
    $stmtUr->execute([$user["id_uporabnik"]]);
    $uciteljRazrednistva = $stmtUr->fetchAll(PDO::FETCH_COLUMN);
}

$inicialke = mb_strtoupper(mb_substr($user["ime"], 0, 1) . mb_substr($user["priimek"], 0, 1));
?>

<div class="profile-page">

<h1>Moj profil</h1>

<?php if ($error): ?>
<div class="error">
    <?= e($error) ?>
</div>
<?php endif; ?>

<div class="profile-header">
    <div class="profile-avatar"><?= e($inicialke) ?></div>
    <div class="profile-header-text">
        <h2><?= e($user["ime"] . " " . $user["priimek"]) ?></h2>
        <div class="profile-meta">
            <span class="profile-badge"><?= e($vlogaLabel) ?></span>
            <?php if ($oddelekIme !== ""): ?>
                <span class="profile-badge profile-badge-light">Razred <?= e($oddelekIme) ?></span>
            <?php endif; ?>
            <?php if ($razrednikIme !== ""): ?>
                <span class="profile-badge profile-badge-light">Razrednik: <?= e($razrednikIme) ?></span>
            <?php endif; ?>
            <?php if (!empty($uciteljRazrednistva)): ?>
                <span class="profile-badge profile-badge-success">
                    Razrednik: <?= e(implode(", ", $uciteljRazrednistva)) ?>
                </span>
            <?php endif; ?>
            <span class="profile-email"><?= e($user["e_posta"]) ?></span>
        </div>
    </div>
</div>

<form method="POST" class="profile-form">

    <section class="profile-section">
        <h3>Osebni podatki</h3>
        <p class="profile-hint">Te podatke vidijo učitelji in administratorji.</p>

        <div class="profile-grid">

            <label>
                Ime
                <input
                    type="text"
                    name="ime"
                    value="<?= e($user["ime"]) ?>"
                    required
                >
            </label>

            <label>
                Priimek
                <input
                    type="text"
                    name="priimek"
                    value="<?= e($user["priimek"]) ?>"
                    required
                >
            </label>

            <label class="profile-wide">
                E-pošta
                <input
                    type="email"
                    name="e_posta"
                    value="<?= e($user["e_posta"]) ?>"
                    required
                >
            </label>

        </div>
    </section>


    <section class="profile-section">
        <h3>Sprememba gesla</h3>
        <p class="profile-hint">Polja pusti prazna, če gesla ne želiš spremeniti. Novo geslo mora imeti vsaj 6 znakov.</p>

        <div class="profile-grid">

            <label class="profile-wide">
                Trenutno geslo
                <input
                    type="password"
                    name="staro_geslo"
                    autocomplete="current-password"
                >
            </label>

            <label>
                Novo geslo
                <input
                    type="password"
                    name="geslo"
                    autocomplete="new-password"
                >
            </label>

            <label>
                Ponovi novo geslo
                <input
                    type="password"
                    name="ponovi_geslo"
                    autocomplete="new-password"
                >
            </label>

        </div>
    </section>


    <div class="profile-actions">
        <button class="btn blue">
            Shrani spremembe
        </button>
    </div>

</form>

</div>

<?php pageEnd(); ?>
