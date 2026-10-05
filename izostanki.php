<?php

require_once "functions.php";

requireLogin();

$user = getUser();


// ==============================
// AKCIJE – DODAJ / UREDI IZOSTANEK
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Samo admin ali učitelj sme dodajati/urejati
    if (!in_array($user["vloga"], ["admin", "ucitelj"], true)) {
        flash("Nimate dovoljenja.");
        redirect("izostanki.php");
    }

    $action = $_POST["action"] ?? "";

    try {

        if ($action === "dodaj_izostanek") {

            $dijak_id = (int) $_POST["dijak_id"];
            $datum    = $_POST["datum"];
            $ura      = (int) $_POST["ura"];
            $status   = $_POST["status"];
            $opomba   = trim($_POST["opomba"]);

            // Preveri, da je veljavna vloga dijaka
            $stmt = $conn->prepare(
                "SELECT 1 FROM Uporabnik WHERE id_uporabnik = ? AND vloga = 'dijak'"
            );
            $stmt->execute([$dijak_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Neveljaven dijak.");
            }

            if (!in_array($status, ["neopraviceno", "opraviceno"], true)) {
                throw new RuntimeException("Neveljaven status.");
            }

            if ($ura < 1 || $ura > 12) {
                throw new RuntimeException("Ura mora biti med 1 in 12.");
            }

            $stmt = $conn->prepare(
                "INSERT INTO Izostanek
                (dijak_id, datum, ura, status, opomba)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $dijak_id,
                $datum,
                $ura,
                $status,
                $opomba
            ]);

            flash("Izostanek je bil dodan.");
        }


        elseif ($action === "uredi_izostanek") {

            $id     = (int) $_POST["id"];
            $status = $_POST["status"];
            $opomba = trim($_POST["opomba"]);

            if (!in_array($status, ["neopraviceno", "opraviceno"], true)) {
                throw new RuntimeException("Neveljaven status.");
            }

            $stmt = $conn->prepare(
                "UPDATE Izostanek
                 SET status = ?, opomba = ?
                 WHERE id_izostanek = ?"
            );

            $stmt->execute([$status, $opomba, $id]);

            flash("Izostanek je bil posodobljen.");
        }


        elseif ($action === "izbrisi_izostanek" && $user["vloga"] === "admin") {

            $id = (int) $_POST["id"];

            $stmt = $conn->prepare(
                "DELETE FROM Izostanek WHERE id_izostanek = ?"
            );

            $stmt->execute([$id]);

            flash("Izostanek je bil izbrisan.");
        }

    } catch (Throwable $e) {

        flash("Napaka: " . $e->getMessage());
    }

    redirect("izostanki.php");
}


// ==============================
// PRIDOBIVANJE PODATKOV
// ==============================

// DIJAK – svoje izostanke
if ($user["vloga"] === "dijak") {

    $stmt = $conn->prepare(
        "SELECT * FROM Izostanek
         WHERE dijak_id = ?
         ORDER BY datum DESC, ura"
    );
    $stmt->execute([$user["id_uporabnik"]]);
    $izostanki = $stmt->fetchAll();

    $dijaki = [];
}


// UČITELJ ali ADMIN
else {

    // Vsi izostanki z imeni dijakov
    $izostanki = $conn->query(
        "SELECT i.*, u.ime, u.priimek
         FROM Izostanek i
         JOIN Uporabnik u ON u.id_uporabnik = i.dijak_id
         ORDER BY i.datum DESC, i.ura"
    )->fetchAll();

    // Seznam dijakov za dodajanje
    $dijaki = $conn->query(
        "SELECT id_uporabnik, ime, priimek
         FROM Uporabnik
         WHERE vloga = 'dijak'
         ORDER BY priimek, ime"
    )->fetchAll();
}


pageStart("Izostanki");

?>

<h1>Izostanki</h1>

<div class="admin-grid">


<?php if (in_array($user["vloga"], ["admin", "ucitelj"], true)): ?>

    <!-- DODAJ IZOSTANEK -->
    <section class="panel">

        <h2>Dodaj izostanek</h2>

        <form method="POST">

            <input type="hidden" name="action" value="dodaj_izostanek">

            <label>
                Dijak
                <select name="dijak_id" required>
                    <option value="">Izberi dijaka</option>
                    <?php foreach ($dijaki as $d): ?>
                    <option value="<?= $d["id_uporabnik"] ?>">
                        <?= e($d["priimek"] . " " . $d["ime"]) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>


            <label>
                Datum
                <input type="date" name="datum" value="<?= date("Y-m-d") ?>" required>
            </label>


            <label>
                Ura (1-12)
                <input type="number" name="ura" min="1" max="12" required>
            </label>


            <label>
                Status
                <select name="status" required>
                    <option value="neopraviceno">Neopravičeno</option>
                    <option value="opraviceno">Opravičeno</option>
                </select>
            </label>


            <label>
                Opomba
                <textarea name="opomba"></textarea>
            </label>


            <button class="btn blue">Dodaj izostanek</button>

        </form>

    </section>

<?php endif; ?>


<!-- PREGLED IZOSTANKOV -->
<section class="panel full">

    <h2>
        <?php if ($user["vloga"] === "dijak"): ?>
            Moji izostanki
        <?php else: ?>
            Vsi izostanki
        <?php endif; ?>
    </h2>

    <?php if (empty($izostanki)): ?>
        <p>Ni izostankov.</p>
    <?php else: ?>

    <table>
        <tr>
            <?php if ($user["vloga"] !== "dijak"): ?>
                <th>Dijak</th>
            <?php endif; ?>
            <th>Datum</th>
            <th>Ura</th>
            <th>Status</th>
            <th>Opomba</th>
            <?php if (in_array($user["vloga"], ["admin", "ucitelj"], true)): ?>
                <th></th>
            <?php endif; ?>
        </tr>

        <?php foreach ($izostanki as $iz): ?>
        <tr>
            <?php if ($user["vloga"] !== "dijak"): ?>
                <td><?= e($iz["priimek"] . " " . $iz["ime"]) ?></td>
            <?php endif; ?>
            <td><?= e($iz["datum"]) ?></td>
            <td><?= e($iz["ura"]) ?>. ura</td>
            <td>
                <span class="badge-<?= $iz["status"] === "opraviceno" ? "ok" : "warn" ?>">
                    <?= $iz["status"] === "opraviceno" ? "Opravičeno" : "Neopravičeno" ?>
                </span>
            </td>
            <td><?= e($iz["opomba"]) ?></td>

            <?php if (in_array($user["vloga"], ["admin", "ucitelj"], true)): ?>
            <td>
                <details>
                    <summary>Uredi</summary>

                    <form method="post">
                        <input type="hidden" name="action" value="uredi_izostanek">
                        <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">

                        <select name="status">
                            <option value="neopraviceno" <?= $iz["status"] === "neopraviceno" ? "selected" : "" ?>>Neopravičeno</option>
                            <option value="opraviceno" <?= $iz["status"] === "opraviceno" ? "selected" : "" ?>>Opravičeno</option>
                        </select>

                        <input type="text" name="opomba" value="<?= e($iz["opomba"]) ?>" placeholder="Opomba">

                        <button class="btn small">Shrani</button>
                    </form>

                    <?php if ($user["vloga"] === "admin"): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="izbrisi_izostanek">
                        <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">
                        <button class="btn red small" onclick="return confirm('Izbrišem izostanek?')">Izbriši</button>
                    </form>
                    <?php endif; ?>

                </details>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>

    </table>

    <?php endif; ?>

</section>

</div>

<?php pageEnd(); ?>
