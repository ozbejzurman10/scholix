<?php

require_once "functions.php";

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

        redirect("profile.php");

    } catch (Throwable $e) {

        $error = $e instanceof PDOException ? "E-poštni naslov je morda že uporabljen." : $e->getMessage();

    }
}

pageStart("Moj profil");

?>

<h1>Moj profil</h1>

<?php if ($error): ?>
<div class="error">
    <?= e($error) ?>
</div>
<?php endif; ?>

<div class="panel">

<form method="POST">

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


    <label>
        E-pošta

        <input
            type="email"
            name="e_posta"
            value="<?= e($user["e_posta"]) ?>"
            required
        >

    </label>


    <label>
        Staro geslo

        <input
            type="password"
            name="staro_geslo"
            placeholder="Potrebno samo ob menjavi gesla"
        >

    </label>


    <label>
        Novo geslo

        <input
            type="password"
            name="geslo"
            placeholder="Pusti prazno, če ga ne želiš spremeniti"
        >

    </label>

    <label>
        Ponovi novo geslo
        <input type="password" name="ponovi_geslo">
    </label>


    <button class="btn blue">
        Shrani spremembe
    </button>

</form>

</div>

<?php pageEnd(); ?>
