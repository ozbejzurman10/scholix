<?php

require_once "functions.php";

requireRole("dijak");

$user = getUser();


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    // DOLOČANJE IN POPRAVLJANJE PREDMETOV (ZAHTEVA 4. POGLAVJA)
    if ($action === "dodaj_predmet") {
        $predmetId = (int) ($_POST["predmet_id"] ?? 0);
        if ($predmetId > 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO Dijak_Predmet (dijak_id, predmet_id) VALUES (?, ?)");
            $stmt->execute([$user["id_uporabnik"], $predmetId]);
            flash("Predmet je bil dodan na vaš seznam.");
        }
        redirect("student.php");
    }

    if ($action === "odstrani_predmet") {
        $predmetId = (int) ($_POST["predmet_id"] ?? 0);
        if ($predmetId > 0) {
            $stmt = $conn->prepare("DELETE FROM Dijak_Predmet WHERE dijak_id = ? AND predmet_id = ?");
            $stmt->execute([$user["id_uporabnik"], $predmetId]);
            flash("Predmet je bil odstranjen z vašega seznama.");
        }
        redirect("student.php");
    }

    // ODDAJA NALOGE – korak 1: začasno shranjevanje
    if ($action === "oddaj_nalogo") {

        $nalogaId = (int) $_POST["naloga_id"];

        // preverimo, ali dijak obiskuje predmet
        $stmt = $conn->prepare(
            "SELECT n.*
             FROM Naloga n
             JOIN Dijak_Predmet dp
               ON dp.predmet_id = n.predmet_id
             WHERE n.id_naloga = ?
             AND dp.dijak_id = ?"
        );

        $stmt->execute([
            $nalogaId,
            $user["id_uporabnik"]
        ]);

        $naloga = $stmt->fetch();

        if (!$naloga) {
            flash("Te naloge ne moreš oddati.");
            redirect("student.php");
        }


        if (!uploadIsValid($_FILES["datoteka"] ?? [])) {
            flash("Datoteka ni veljavna ali je večja od 40 MB.");
            redirect("student.php");
        }


        $original = $_FILES["datoteka"]["name"];

        $extension = strtolower(
            pathinfo($original, PATHINFO_EXTENSION)
        );

        if ($extension === "" || !preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            flash("Datoteka nima veljavne končnice.");
            redirect("student.php");
        }

        // Prepreči PHP datoteke
        if (in_array($extension, ["php", "phtml", "phar", "php3", "php4", "php5", "php7", "phps"], true)) {
            flash("Nalaganje PHP datotek ni dovoljeno.");
            redirect("student.php");
        }


        $filename = safeFileName(
            $user["priimek"] .
            " " .
            $user["ime"] .
            " – " .
            $naloga["naslov"]
        );

        if ($extension !== "") {
            $filename .= "." . $extension;
        }


        $relativePath = "uploads/naloge/" . $filename;
        $fullPath = __DIR__ . "/" . $relativePath;

        ensureUploadDirectory("uploads/naloge");

        // Preveri, ali oddaja že obstaja
        $stmt = $conn->prepare(
            "SELECT id_oddana, datoteka_pot FROM Oddana_Naloga WHERE naloga_id = ? AND dijak_id = ?"
        );
        $stmt->execute([$nalogaId, $user["id_uporabnik"]]);
        $stara = $stmt->fetch();


        // Če stara oddaja obstaja IN uporabnik NI potrdil povoza
        if ($stara && empty($_POST["potrdi_povoz"])) {

            // Shrani datoteko začasno
            $tmpDir = __DIR__ . "/uploads/naloge/tmp_" . $user["id_uporabnik"];
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            $tmpPath = $tmpDir . "/" . $filename;

            if (!move_uploaded_file($_FILES["datoteka"]["tmp_name"], $tmpPath)) {
                flash("Datoteke ni bilo mogoče shraniti.");
                redirect("student.php");
            }

            // Nastavi potrditev v sejo
            $_SESSION["potrditev"] = [
                "naloga_id"     => $nalogaId,
                "tmp_path"      => $tmpPath,
                "relative_path" => $relativePath,
                "full_path"     => $fullPath,
                "komentar"      => trim($_POST["komentar"] ?? ""),
                "stara_id"      => $stara["id_oddana"],
                "sporocilo"     => "Za to nalogo že obstaja oddaja. Ali jo želiš povoziti?"
            ];

            redirect("student.php");
        }


        // Če je potrditev ali nova oddaja
        if ($stara && !empty($_POST["potrdi_povoz"])) {

            // Uporabimo začasno datoteko iz seje
            $potrditev = $_SESSION["potrditev"] ?? null;

            if ($potrditev && (int)$potrditev["naloga_id"] === $nalogaId && file_exists($potrditev["tmp_path"])) {
                // Premakni začasno datoteko na končno lokacijo
                if (file_exists($potrditev["full_path"])) {
                    unlink($potrditev["full_path"]);
                }
                rename($potrditev["tmp_path"], $potrditev["full_path"]);

                $stmt = $conn->prepare(
                    "UPDATE Oddana_Naloga
                     SET datoteka_pot = ?,
                         komentar_dijaka = ?,
                         oddano_ob = NOW()
                     WHERE id_oddana = ?"
                );

                $stmt->execute([
                    $potrditev["relative_path"],
                    $potrditev["komentar"],
                    $potrditev["stara_id"]
                ]);

                // Počisti tmp mapo
                $tmpDir = dirname($potrditev["tmp_path"]);
                if (is_dir($tmpDir)) {
                    @rmdir($tmpDir);
                }

                unset($_SESSION["potrditev"]);
                flash("Naloga je bila uspešno ponovno oddana.");
                redirect("student.php");

            } else {
                // Tmp datoteka ne obstaja, poskusi novo oddajo z naloženo datoteko
                if (!move_uploaded_file($_FILES["datoteka"]["tmp_name"], $fullPath)) {
                    flash("Datoteke ni bilo mogoče shraniti.");
                    redirect("student.php");
                }

                $stmt = $conn->prepare(
                    "UPDATE Oddana_Naloga
                     SET datoteka_pot = ?,
                         komentar_dijaka = ?,
                         oddano_ob = NOW()
                     WHERE id_oddana = ?"
                );

                $stmt->execute([
                    $relativePath,
                    trim($_POST["komentar"] ?? ""),
                    $stara["id_oddana"]
                ]);

                unset($_SESSION["potrditev"]);
                flash("Naloga je bila uspešno ponovno oddana.");
                redirect("student.php");
            }
        }

        // NOVA ODDAJA (brez stare)
        if (!move_uploaded_file(
            $_FILES["datoteka"]["tmp_name"],
            $fullPath
        )) {
            flash("Datoteke ni bilo mogoče shraniti.");
            redirect("student.php");
        }

        $stmt = $conn->prepare(
            "INSERT INTO Oddana_Naloga
            (naloga_id,
             dijak_id,
             datoteka_pot,
             komentar_dijaka)
            VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $nalogaId,
            $user["id_uporabnik"],
            $relativePath,
            trim($_POST["komentar"] ?? "")
        ]);


        unset($_SESSION["potrditev"]);

        flash("Naloga je bila uspešno oddana.");

        redirect("student.php");
    }


    // POTRDI POVOZ (iz potrditve, brez datoteke)
    if ($action === "potrdi_povoz") {

        $potrditev = $_SESSION["potrditev"] ?? null;

        if ($potrditev && file_exists($potrditev["tmp_path"])) {

            // Premakni začasno datoteko na končno lokacijo
            if (file_exists($potrditev["full_path"])) {
                unlink($potrditev["full_path"]);
            }
            rename($potrditev["tmp_path"], $potrditev["full_path"]);

            $stmt = $conn->prepare(
                "UPDATE Oddana_Naloga
                 SET datoteka_pot = ?,
                     komentar_dijaka = ?,
                     oddano_ob = NOW()
                 WHERE id_oddana = ?"
            );

            $stmt->execute([
                $potrditev["relative_path"],
                $potrditev["komentar"],
                $potrditev["stara_id"]
            ]);

            // Počisti tmp mapo
            $tmpDir = dirname($potrditev["tmp_path"]);
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }

            unset($_SESSION["potrditev"]);
            flash("Naloga je bila uspešno ponovno oddana.");

        } else {
            unset($_SESSION["potrditev"]);
            flash("Začasna datoteka je potekla. Prosimo, ponovno oddajte nalogo.");
        }

        redirect("student.php");
    }


    // PREKLIČI POVOZ
    if ($action === "preklici_povoz") {

        $potrditev = $_SESSION["potrditev"] ?? null;

        if ($potrditev && isset($potrditev["tmp_path"]) && file_exists($potrditev["tmp_path"])) {
            unlink($potrditev["tmp_path"]);
            $tmpDir = dirname($potrditev["tmp_path"]);
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }

        unset($_SESSION["potrditev"]);
        flash("Ponovna oddaja je bila preklicana.");
        redirect("student.php");
    }
}


// ==============================
// PREDMETI DIJAKA
// ==============================


$stmt = $conn->prepare(
    "SELECT p.*
     FROM Predmet p
     JOIN Dijak_Predmet dp
       ON dp.predmet_id = p.id_predmet
     WHERE dp.dijak_id = ?
     ORDER BY p.naziv"
);

$stmt->execute([
    $user["id_uporabnik"]
]);

$mojiPredmeti = $stmt->fetchAll();
$mojiPredmetiIds = array_map('intval', array_column($mojiPredmeti, "id_predmet"));
$vsiPredmeti = $conn->query("SELECT * FROM Predmet ORDER BY naziv")->fetchAll();


// ==============================
// GRADIVA
// ==============================

$studentOddelekId = (int) ($user["oddelek_id"] ?? 0);

$stmt = $conn->prepare(
    "SELECT
        g.*,
        p.naziv AS predmet,
        GROUP_CONCAT(o.ime ORDER BY o.ime SEPARATOR ', ') AS oddelki_imena

     FROM Gradivo g

     JOIN Predmet p
       ON p.id_predmet = g.predmet_id

     JOIN Dijak_Predmet dp
       ON dp.predmet_id = p.id_predmet

     LEFT JOIN Gradivo_Oddelek go
       ON go.gradivo_id = g.id_gradivo

     LEFT JOIN Oddelek o
       ON o.id_oddelek = go.oddelek_id

     WHERE dp.dijak_id = ?
       AND (
           NOT EXISTS (SELECT 1 FROM Gradivo_Oddelek go1 WHERE go1.gradivo_id = g.id_gradivo)
           OR EXISTS (
               SELECT 1 FROM Gradivo_Oddelek go2
               WHERE go2.gradivo_id = g.id_gradivo
                 AND go2.oddelek_id = ?
           )
       )

     GROUP BY g.id_gradivo
     ORDER BY g.ustvarjeno DESC"
);

$stmt->execute([
    $user["id_uporabnik"],
    $studentOddelekId
]);

$gradiva = $stmt->fetchAll();


// ==============================
// NALOGE
// ==============================

$stmt = $conn->prepare(
    "SELECT
        n.*,
        p.naziv AS predmet

     FROM Naloga n

     JOIN Predmet p
       ON p.id_predmet = n.predmet_id

     JOIN Dijak_Predmet dp
       ON dp.predmet_id = p.id_predmet

     WHERE dp.dijak_id = ?

     ORDER BY n.rok_oddaje"
);

$stmt->execute([
    $user["id_uporabnik"]
]);

$naloge = $stmt->fetchAll();


// ==============================
// STANJE ODDAJ
// ==============================

$stmt = $conn->prepare(
    "SELECT naloga_id, id_oddana, datoteka_pot, oddano_ob, ocena, komentar_ucitelja
     FROM Oddana_Naloga
     WHERE dijak_id = ?"
);
$stmt->execute([$user["id_uporabnik"]]);
$oddaje_raw = $stmt->fetchAll();

$oddajeMap = [];
foreach ($oddaje_raw as $od) {
    $oddajeMap[$od["naloga_id"]] = $od;
}


pageStart("Dijak");

?>

<h1>Moja učilnica</h1>


<?php
$potrditev = $_SESSION["potrditev"] ?? null;
if ($potrditev):
?>

<div class="confirm-box">

    <strong>
        <?= e($potrditev["sporocilo"]) ?>
    </strong>

    <div style="margin-top: 10px; display: flex; gap: 10px;">
        <form method="POST">
            <input type="hidden" name="action" value="potrdi_povoz">
            <button class="btn blue">Da, povozi</button>
        </form>

        <form method="POST">
            <input type="hidden" name="action" value="preklici_povoz">
            <button class="btn red">Prekliči</button>
        </form>
    </div>

</div>

<?php endif; ?>


<div class="admin-grid">


<!-- PREDMETI -->

<section class="panel">

<h2>Moji predmeti</h2>
<p style="font-size: 13px; color: var(--muted); margin-top: -6px; margin-bottom: 14px;">
    Tukaj lahko določate in urejate seznam predmetov, ki jih obiskujete.
</p>

<?php if (empty($mojiPredmeti)): ?>

    <p style="color: var(--muted);">Trenutno nimate izbranega nobenega predmeta. Izberite predmete spodaj.</p>

<?php else: ?>

    <?php foreach ($mojiPredmeti as $p): ?>

    <div class="list-row" style="display: flex; justify-content: space-between; align-items: center;">

        <div>
            <strong><?= e($p["naziv"]) ?></strong>
            <?php if (!empty($p["opis"])): ?>
                <br><small class="muted"><?= e($p["opis"]) ?></small>
            <?php endif; ?>
        </div>

        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="odstrani_predmet">
            <input type="hidden" name="predmet_id" value="<?= (int)$p["id_predmet"] ?>">
            <button class="btn red small" onclick="return confirm('Ali res želite odstraniti ta predmet s svojega seznama?')" style="padding: 4px 8px; font-size: 11px;">
                Odstrani
            </button>
        </form>

    </div>

    <?php endforeach; ?>

<?php endif; ?>

<!-- OBRAZEC ZA DODAJANJE PREDMETA -->
<form method="POST" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <input type="hidden" name="action" value="dodaj_predmet">

    <select name="predmet_id" required style="flex: 1; min-width: 200px; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--border);">
        <option value="">-- Izberite predmet za vpis --</option>
        <?php foreach ($vsiPredmeti as $vp): ?>
            <?php if (!in_array((int)$vp["id_predmet"], $mojiPredmetiIds, true)): ?>
                <option value="<?= (int)$vp["id_predmet"] ?>"><?= e($vp["naziv"]) ?></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>

    <button class="btn blue small" style="white-space: nowrap;">+ Dodaj predmet</button>
</form>

</section>


<!-- GRADIVA -->

<section class="panel">

<h2>Gradiva</h2>


<?php foreach ($gradiva as $g): ?>

<div class="list-row">

<div>

<strong>
<?= e($g["naslov"]) ?>
</strong>

<div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
    <span><?= e($g["predmet"]) ?></span>
    <?php if (!empty($g["oddelki_imena"])): ?>
        &bull; <span>Razred: <?= e($g["oddelki_imena"]) ?></span>
    <?php endif; ?>
</div>

<p>
<?= e($g["opis"]) ?>
</p>

</div>


<a
    href="<?= e($g["datoteka_pot"]) ?>"
    target="_blank"
    class="btn small"
>
    Odpri
</a>

</div>

<?php endforeach; ?>


<?php if (!$gradiva): ?>

<p>
Ni gradiv.
</p>

<?php endif; ?>

</section>


<!-- NALOGE -->

<section class="panel full">

<h2>Naloge</h2>


<?php foreach ($naloge as $n): ?>

<?php
    $oddaja = $oddajeMap[$n["id_naloga"]] ?? null;
    $jeOddana = $oddaja !== null;
?>

<div class="task">

<h3>
<?= e($n["naslov"]) ?>
</h3>

<p>

<strong>
Predmet:
</strong>

<?= e($n["predmet"]) ?>

</p>


<p>

<strong>
Navodila:
</strong>

<?= e($n["navodila"]) ?>

</p>


<p>

<strong>
Rok:
</strong>

<?= e($n["rok_oddaje"]) ?>

</p>


<p>
<strong>Stanje:</strong>
<?php if ($jeOddana): ?>
    <span class="badge-ok">Oddano (<?= e($oddaja["oddano_ob"]) ?>)</span>
    <?php if ($oddaja["ocena"] !== null): ?>
        — Ocena: <strong><?= e($oddaja["ocena"]) ?></strong>
        <?php if ($oddaja["komentar_ucitelja"]): ?>
            (<?= e($oddaja["komentar_ucitelja"]) ?>)
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <span class="badge-warn">Ni oddano</span>
<?php endif; ?>
</p>


<?php if (!$potrditev || (int)$potrditev["naloga_id"] !== (int)$n["id_naloga"]): ?>
<form
    method="POST"
    enctype="multipart/form-data"
>

<input type="hidden"
       name="action"
       value="oddaj_nalogo">


<input type="hidden"
       name="naloga_id"
       value="<?= $n["id_naloga"] ?>">


<input
    type="file"
    name="datoteka"
    required
>


<input
    type="text"
    name="komentar"
    placeholder="Komentar"
>


<button class="btn blue">
<?= $jeOddana ? "Oddaj ponovno" : "Oddaj nalogo" ?>
</button>

</form>
<?php endif; ?>

</div>

<?php endforeach; ?>


<?php if (!$naloge): ?>

<p>
Trenutno ni nalog.
</p>

<?php endif; ?>

</section>

</div>


<?php pageEnd(); ?>
