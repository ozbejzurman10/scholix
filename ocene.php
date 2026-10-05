<?php

require_once "functions.php";

requireLogin();

$user = getUser();


// ==============================
// AKCIJE UČITELJA – DODAJ OCENO
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        // UČITELJ DODAJA OCENO
        if ($action === "dodaj_oceno" && $user["vloga"] === "ucitelj") {

            $dijak_id   = (int) $_POST["dijak_id"];
            $predmet_id = (int) $_POST["predmet_id"];
            $ocena      = (int) $_POST["ocena"];
            $naslov     = trim($_POST["naslov_ocene"]);
            $opis       = trim($_POST["opis"]);
            $datum      = $_POST["datum_ocenitve"];

            // Preveri, da učitelj poučuje ta predmet
            $stmt = $conn->prepare(
                "SELECT 1 FROM Ucitelj_Predmet
                 WHERE ucitelj_id = ? AND predmet_id = ?"
            );
            $stmt->execute([$user["id_uporabnik"], $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }

            // Preveri, da dijak obiskuje ta predmet
            $stmt = $conn->prepare(
                "SELECT 1 FROM Dijak_Predmet
                 WHERE dijak_id = ? AND predmet_id = ?"
            );
            $stmt->execute([$dijak_id, $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Dijak ne obiskuje tega predmeta.");
            }

            if ($ocena < 1 || $ocena > 5) {
                throw new RuntimeException("Ocena mora biti med 1 in 5.");
            }

            $stmt = $conn->prepare(
                "INSERT INTO Ocena
                (dijak_id, predmet_id, ucitelj_id, ocena, naslov_ocene, opis, datum_ocenitve)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $dijak_id,
                $predmet_id,
                $user["id_uporabnik"],
                $ocena,
                $naslov,
                $opis,
                $datum
            ]);

            flash("Ocena je bila dodana.");
        }


        // UČITELJ OCENJUJE ODDANO NALOGO
        elseif ($action === "oceni_oddajo" && $user["vloga"] === "ucitelj") {

            $id_oddana = (int) $_POST["id_oddana"];
            $ocena     = (int) $_POST["ocena"];
            $komentar  = trim($_POST["komentar_ucitelja"]);

            // Preveri, da oddaja pripada učiteljevi nalogi
            $stmt = $conn->prepare(
                "SELECT o.id_oddana
                 FROM Oddana_Naloga o
                 JOIN Naloga n ON n.id_naloga = o.naloga_id
                 WHERE o.id_oddana = ? AND n.ucitelj_id = ?"
            );
            $stmt->execute([$id_oddana, $user["id_uporabnik"]]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Ta oddaja ne pripada vaši nalogi.");
            }

            $stmt = $conn->prepare(
                "UPDATE Oddana_Naloga
                 SET ocena = ?, komentar_ucitelja = ?, ocenjeno_ob = NOW()
                 WHERE id_oddana = ?"
            );

            $stmt->execute([$ocena, $komentar, $id_oddana]);

            flash("Oddaja je bila ocenjena.");
        }

    } catch (Throwable $e) {

        flash("Napaka: " . $e->getMessage());
    }

    redirect("ocene.php");
}


// ==============================
// PRIDOBIVANJE PODATKOV
// ==============================

// DIJAK – svoje ocene
if ($user["vloga"] === "dijak") {

    $stmt = $conn->prepare(
        "SELECT o.*, p.naziv AS predmet,
                u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
         FROM Ocena o
         JOIN Predmet p ON p.id_predmet = o.predmet_id
         JOIN Uporabnik u ON u.id_uporabnik = o.ucitelj_id
         WHERE o.dijak_id = ?
         ORDER BY o.datum_ocenitve DESC"
    );
    $stmt->execute([$user["id_uporabnik"]]);
    $ocene = $stmt->fetchAll();
}


// UČITELJ – ocene svojih predmetov + podatki za dodajanje
elseif ($user["vloga"] === "ucitelj") {

    // Učiteljevi predmeti
    $stmt = $conn->prepare(
        "SELECT p.* FROM Predmet p
         JOIN Ucitelj_Predmet up ON up.predmet_id = p.id_predmet
         WHERE up.ucitelj_id = ?
         ORDER BY p.naziv"
    );
    $stmt->execute([$user["id_uporabnik"]]);
    $predmeti = $stmt->fetchAll();

    $predmetIds = array_column($predmeti, "id_predmet");

    // Dijaki teh predmetov
    if ($predmetIds) {
        $placeholders = implode(",", array_fill(0, count($predmetIds), "?"));
        $stmt = $conn->prepare(
            "SELECT DISTINCT u.id_uporabnik, u.ime, u.priimek
             FROM Uporabnik u
             JOIN Dijak_Predmet dp ON dp.dijak_id = u.id_uporabnik
             WHERE dp.predmet_id IN ($placeholders)
             ORDER BY u.priimek, u.ime"
        );
        $stmt->execute($predmetIds);
        $dijaki = $stmt->fetchAll();
    } else {
        $dijaki = [];
    }

    // Ocene učiteljevih predmetov
    if ($predmetIds) {
        $stmt = $conn->prepare(
            "SELECT o.*, p.naziv AS predmet,
                    u.ime AS dijak_ime, u.priimek AS dijak_priimek
             FROM Ocena o
             JOIN Predmet p ON p.id_predmet = o.predmet_id
             JOIN Uporabnik u ON u.id_uporabnik = o.dijak_id
             WHERE o.ucitelj_id = ?
             ORDER BY o.datum_ocenitve DESC"
        );
        $stmt->execute([$user["id_uporabnik"]]);
        $ocene = $stmt->fetchAll();
    } else {
        $ocene = [];
    }
}


// ADMIN – vse ocene
elseif ($user["vloga"] === "admin") {

    $ocene = $conn->query(
        "SELECT o.*, p.naziv AS predmet,
                d.ime AS dijak_ime, d.priimek AS dijak_priimek,
                u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
         FROM Ocena o
         JOIN Predmet p ON p.id_predmet = o.predmet_id
         JOIN Uporabnik d ON d.id_uporabnik = o.dijak_id
         JOIN Uporabnik u ON u.id_uporabnik = o.ucitelj_id
         ORDER BY o.datum_ocenitve DESC"
    )->fetchAll();
}


pageStart("Ocene");

?>

<h1>Ocene</h1>

<div class="admin-grid">


<?php if ($user["vloga"] === "ucitelj"): ?>

    <!-- DODAJ OCENO -->
    <section class="panel">

        <h2>Dodaj oceno</h2>

        <form method="POST">

            <input type="hidden" name="action" value="dodaj_oceno">

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
                Predmet
                <select name="predmet_id" required>
                    <option value="">Izberi predmet</option>
                    <?php foreach ($predmeti as $p): ?>
                    <option value="<?= $p["id_predmet"] ?>">
                        <?= e($p["naziv"]) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>


            <label>
                Ocena (1-5)
                <input type="number" name="ocena" min="1" max="5" required>
            </label>


            <label>
                Naslov ocene
                <input type="text" name="naslov_ocene" required>
            </label>


            <label>
                Opis
                <textarea name="opis"></textarea>
            </label>


            <label>
                Datum ocenitve
                <input type="date" name="datum_ocenitve" value="<?= date("Y-m-d") ?>" required>
            </label>


            <button class="btn blue">Dodaj oceno</button>

        </form>

    </section>

<?php endif; ?>


<!-- PREGLED OCEN -->
<section class="panel full">

    <h2>
        <?php if ($user["vloga"] === "dijak"): ?>
            Moje ocene
        <?php elseif ($user["vloga"] === "ucitelj"): ?>
            Dodeljene ocene
        <?php else: ?>
            Vse ocene
        <?php endif; ?>
    </h2>

    <?php if (empty($ocene)): ?>
        <p>Ni ocen.</p>
    <?php else: ?>

    <table>
        <tr>
            <?php if ($user["vloga"] !== "dijak"): ?>
                <th>Dijak</th>
            <?php endif; ?>
            <th>Predmet</th>
            <th>Ocena</th>
            <th>Naslov</th>
            <th>Opis</th>
            <th>Datum</th>
            <?php if ($user["vloga"] === "admin"): ?>
                <th>Učitelj</th>
            <?php endif; ?>
        </tr>

        <?php foreach ($ocene as $o): ?>
        <tr>
            <?php if ($user["vloga"] !== "dijak"): ?>
                <td><?= e($o["dijak_priimek"] . " " . $o["dijak_ime"]) ?></td>
            <?php endif; ?>
            <td><?= e($o["predmet"]) ?></td>
            <td><strong><?= e($o["ocena"]) ?></strong></td>
            <td><?= e($o["naslov_ocene"]) ?></td>
            <td><?= e($o["opis"]) ?></td>
            <td><?= e($o["datum_ocenitve"]) ?></td>
            <?php if ($user["vloga"] === "admin"): ?>
                <td><?= e($o["ucitelj_priimek"] . " " . $o["ucitelj_ime"]) ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>

    </table>

    <?php endif; ?>

</section>

</div>

<?php pageEnd(); ?>
