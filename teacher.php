<?php

require_once "functions.php";

requireRole("ucitelj");

$user = getUser();


// ==============================
// AKCIJE
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        // NALAGANJE GRADIVA

        if ($action === "dodaj_gradivo") {

            $predmet = $_POST["predmet_id"];

            $stmt = $conn->prepare(
                "SELECT *
                 FROM Ucitelj_Predmet
                 WHERE ucitelj_id = ?
                 AND predmet_id = ?"
            );

            $stmt->execute([
                $user["id_uporabnik"],
                $predmet
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }


            if (!uploadIsValid($_FILES["datoteka"] ?? [])) {
                throw new RuntimeException("Datoteka ni veljavna ali je večja od 40 MB.");
            }

            $naslov = trim($_POST["naslov"]);

            $original = $_FILES["datoteka"]["name"];

            $ext = strtolower(
                pathinfo($original, PATHINFO_EXTENSION)
            );

            if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
                throw new RuntimeException("Datoteka nima veljavne končnice.");
            }

            // Prepreči PHP datoteke
            if (in_array($ext, ["php", "phtml", "phar", "php3", "php4", "php5", "php7", "phps"], true)) {
                throw new RuntimeException("Nalaganje PHP datotek ni dovoljeno.");
            }

            ensureUploadDirectory("uploads/gradivo");

            $cleanBase = safeFileName($naslov);
            $filename = $cleanBase . "." . $ext;

            if (file_exists(__DIR__ . "/uploads/gradivo/" . $filename)) {
                $filename = $cleanBase . "_" . time() . "." . $ext;
            }

            $path = "uploads/gradivo/" . $filename;

            if (!move_uploaded_file(
                $_FILES["datoteka"]["tmp_name"],
                __DIR__ . "/" . $path
            )) {
                throw new RuntimeException("Napaka pri shranjevanju datoteke.");
            }


            $stmt = $conn->prepare(
                "INSERT INTO Gradivo
                (predmet_id, ucitelj_id, naslov, opis, datoteka_pot)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $predmet,
                $user["id_uporabnik"],
                $naslov,
                trim($_POST["opis"]),
                $path
            ]);

            $gradivoId = (int) $conn->lastInsertId();

            $vsiOddelki = !empty($_POST["vsi_oddelki"]);
            $izbraniOddelki = isset($_POST["oddelki"]) && is_array($_POST["oddelki"])
                ? array_filter(array_map('intval', $_POST["oddelki"]))
                : [];

            if (!$vsiOddelki && !empty($izbraniOddelki)) {
                $stmtGo = $conn->prepare(
                    "INSERT INTO Gradivo_Oddelek (gradivo_id, oddelek_id) VALUES (?, ?)"
                );
                foreach ($izbraniOddelki as $oddId) {
                    $stmtGo->execute([$gradivoId, $oddId]);
                }
            }

            flash("Gradivo je bilo naloženo.");
        }


        // BRISANJE GRADIVA

        elseif ($action === "izbrisi_gradivo") {

            $stmt = $conn->prepare(
                "SELECT *
                 FROM Gradivo
                 WHERE id_gradivo = ?
                 AND ucitelj_id = ?"
            );

            $stmt->execute([
                $_POST["id"],
                $user["id_uporabnik"]
            ]);

            $gradivo = $stmt->fetch();


            if ($gradivo) {

                $file = __DIR__ . "/" . $gradivo["datoteka_pot"];

                if (file_exists($file)) {
                    unlink($file);
                }

                $conn->prepare("DELETE FROM Gradivo_Oddelek WHERE gradivo_id = ?")->execute([$gradivo["id_gradivo"]]);

                $stmt = $conn->prepare(
                    "DELETE FROM Gradivo
                     WHERE id_gradivo = ?"
                );

                $stmt->execute([
                    $gradivo["id_gradivo"]
                ]);
            }

            flash("Gradivo je bilo izbrisano.");
        }


        // DODAJ NALOGO

        elseif ($action === "dodaj_nalogo") {

            $stmt = $conn->prepare(
                "SELECT *
                 FROM Ucitelj_Predmet
                 WHERE ucitelj_id = ?
                 AND predmet_id = ?"
            );

            $stmt->execute([
                $user["id_uporabnik"],
                $_POST["predmet_id"]
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }


            $stmt = $conn->prepare(
                "INSERT INTO Naloga
                (predmet_id, ucitelj_id, naslov, navodila, rok_oddaje)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $_POST["predmet_id"],
                $user["id_uporabnik"],
                trim($_POST["naslov"]),
                trim($_POST["navodila"]),
                $_POST["rok_oddaje"]
            ]);

            flash("Naloga je bila objavljena.");
        }


        // OCENI ODDANO NALOGO

        elseif ($action === "oceni_oddajo") {

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


        // VPIS DIJAKA K PREDMETU
        elseif ($action === "povezi_dijaka") {

            $predmet_id = (int) $_POST["predmet_id"];
            $dijak_id   = (int) $_POST["dijak_id"];

            // Preveri, da učitelj dejansko poučuje ta predmet
            $stmt = $conn->prepare(
                "SELECT 1 FROM Ucitelj_Predmet
                 WHERE ucitelj_id = ? AND predmet_id = ?"
            );
            $stmt->execute([$user["id_uporabnik"], $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }

            // Preveri, da je izbrani uporabnik dijak
            $stmt = $conn->prepare(
                "SELECT 1 FROM Uporabnik WHERE id_uporabnik = ? AND vloga = 'dijak'"
            );
            $stmt->execute([$dijak_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Neveljaven dijak.");
            }

            try {
                $stmt = $conn->prepare(
                    "INSERT INTO Dijak_Predmet (dijak_id, predmet_id)
                     VALUES (?, ?)"
                );
                $stmt->execute([$dijak_id, $predmet_id]);

                flash("Dijak je bil uspešno vpisan k predmetu.");

            } catch (PDOException $e) {
                flash("Dijak je že vpisan k temu predmetu.");
            }
        }


        // ODSTRANITEV DIJAKA S PREDMETA
        elseif ($action === "odstrani_dijaka") {

            $predmet_id = (int) $_POST["predmet_id"];
            $dijak_id   = (int) $_POST["dijak_id"];

            // Preveri, da učitelj poučuje ta predmet
            $stmt = $conn->prepare(
                "SELECT 1 FROM Ucitelj_Predmet
                 WHERE ucitelj_id = ? AND predmet_id = ?"
            );
            $stmt->execute([$user["id_uporabnik"], $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }

            $stmt = $conn->prepare(
                "DELETE FROM Dijak_Predmet
                 WHERE dijak_id = ? AND predmet_id = ?"
            );
            $stmt->execute([$dijak_id, $predmet_id]);

            flash("Dijak je bil odstranjen s predmeta.");
        }


    } catch (Throwable $e) {

        flash("Napaka: " . $e->getMessage());
    }

    redirect("teacher.php");
}


// ==============================
// PREDMETI UČITELJA
// ==============================

$stmt = $conn->prepare(
    "SELECT p.*
     FROM Predmet p
     JOIN Ucitelj_Predmet up
       ON up.predmet_id = p.id_predmet
     WHERE up.ucitelj_id = ?
     ORDER BY p.naziv"
);

$stmt->execute([
    $user["id_uporabnik"]
]);

$predmeti = $stmt->fetchAll();


// ==============================
// ODDELKI
// ==============================

$oddelki = $conn->query("SELECT * FROM Oddelek ORDER BY letnik, ime")->fetchAll();
$oddelkiPoLetnikih = [];
foreach ($oddelki as $o) {
    $oddelkiPoLetnikih[$o["letnik"]][] = $o;
}
ksort($oddelkiPoLetnikih);


// ==============================
// GRADIVA
// ==============================

$stmt = $conn->prepare(
    "SELECT g.*, p.naziv,
            GROUP_CONCAT(o.ime ORDER BY o.ime SEPARATOR ', ') AS oddelki_imena
     FROM Gradivo g
     JOIN Predmet p
       ON p.id_predmet = g.predmet_id
     LEFT JOIN Gradivo_Oddelek go
       ON go.gradivo_id = g.id_gradivo
     LEFT JOIN Oddelek o
       ON o.id_oddelek = go.oddelek_id
     WHERE g.ucitelj_id = ?
     GROUP BY g.id_gradivo
     ORDER BY g.ustvarjeno DESC"
);

$stmt->execute([
    $user["id_uporabnik"]
]);

$gradiva = $stmt->fetchAll();


// ==============================
// ODDANE NALOGE
// ==============================

$stmt = $conn->prepare(
    "SELECT
        o.*,
        n.naslov,
        p.naziv AS predmet,
        u.ime,
        u.priimek

     FROM Oddana_Naloga o

     JOIN Naloga n
       ON n.id_naloga = o.naloga_id

     JOIN Predmet p
       ON p.id_predmet = n.predmet_id

     JOIN Uporabnik u
       ON u.id_uporabnik = o.dijak_id

     WHERE n.ucitelj_id = ?

     ORDER BY o.oddano_ob DESC"
);

$stmt->execute([
    $user["id_uporabnik"]
]);

$oddane = $stmt->fetchAll();


// ==============================
// DIJAKI IN VPIS K PREDMETOM
// ==============================

// Vsi dijaki z oddelki
$vsiDijaki = $conn->query(
    "SELECT u.id_uporabnik, u.ime, u.priimek, o.ime AS oddelek_ime
     FROM Uporabnik u
     LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
     WHERE u.vloga = 'dijak'
     ORDER BY o.ime, u.priimek, u.ime"
)->fetchAll();

// Vpisani dijaki pri profesorjevih predmetih
$vpisaniDijaki = [];
if (!empty($predmeti)) {
    $pIds = array_column($predmeti, "id_predmet");
    $placeholders = implode(",", array_fill(0, count($pIds), "?"));
    $stmt = $conn->prepare(
        "SELECT dp.predmet_id, dp.dijak_id, p.naziv AS predmet_naziv,
                u.ime AS dijak_ime, u.priimek AS dijak_priimek, o.ime AS oddelek_ime
         FROM Dijak_Predmet dp
         JOIN Predmet p ON p.id_predmet = dp.predmet_id
         JOIN Uporabnik u ON u.id_uporabnik = dp.dijak_id
         LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
         WHERE dp.predmet_id IN ($placeholders)
         ORDER BY p.naziv, o.ime, u.priimek, u.ime"
    );
    $stmt->execute($pIds);
    $vpisaniDijaki = $stmt->fetchAll();
}


pageStart("Učitelj");

?>

<h1>Učiteljski modul</h1>


<div class="admin-grid">


<section class="panel">

<h2>Naloži gradivo</h2>

<form method="POST"
      enctype="multipart/form-data">

<input type="hidden"
       name="action"
       value="dodaj_gradivo">


<label>
    Predmet

    <select name="predmet_id" required>

        <option value="">
            Izberi predmet
        </option>

        <?php foreach ($predmeti as $p): ?>

        <option value="<?= $p["id_predmet"] ?>">

            <?= e($p["naziv"]) ?>

        </option>

        <?php endforeach; ?>

    </select>

</label>


<label>
    Naslov

    <input
        type="text"
        name="naslov"
        required
    >

</label>


<label>
    Opis

    <textarea name="opis"></textarea>

</label>


<div style="width: 100%; margin: 4px 0 8px;">
    <span style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">
        Ciljni razredi / oddelki
    </span>
    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 12px;">
        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; margin-bottom: 10px;">
            <input type="checkbox" id="vsi_oddelki_cb" name="vsi_oddelki" value="1" checked onchange="toggleVsiOddelki(this)">
            Vsi razredi (dostopno vsem vpisanim dijakom)
        </label>

        <div style="border-top: 1px solid var(--border); padding-top: 10px;">
            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 8px;">
                Ali izberite posamezne razrede:
            </div>
            <div style="display: grid; gap: 8px;">
                <?php foreach ($oddelkiPoLetnikih as $letnik => $oddSeznam): ?>
                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 12px;">
                    <span style="min-width: 65px; font-weight: 600; color: var(--blue-dark);"><?= (int)$letnik ?>. letnik:</span>
                    <?php foreach ($oddSeznam as $o): ?>
                    <label style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #fff; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-weight: normal; margin: 0;">
                        <input type="checkbox" name="oddelki[]" value="<?= $o["id_oddelek"] ?>" class="oddelek-cb" onchange="togglePosamezniOddelek(this)">
                        <?= e($o["ime"]) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <small style="display: block; color: var(--muted); margin-top: 8px;">
            Če izberete določene razrede, bo gradivo vidno le dijakom tistih razredov.
        </small>
    </div>
</div>


<label>
    Datoteka

    <input
        type="file"
        name="datoteka"
        accept=".ppt,.pptx,.ppsx,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt"
        required
    >
    <small style="display:block; color:#666; margin-top:4px;">Podprte so PowerPoint (.pptx, .ppt), PDF, Word, Excel in druge datoteke do 40 MB.</small>

</label>


<button class="btn blue">
    Naloži gradivo
</button>

</form>

</section>


<section class="panel">

<h2>Moja gradiva</h2>

<?php foreach ($gradiva as $g): ?>

<div class="list-row">

<div>

<strong>
<?= e($g["naslov"]) ?>
</strong>

<div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
    <span>Predmet: <?= e($g["naziv"]) ?></span>
    &bull;
    <span>Razred: <?= !empty($g["oddelki_imena"]) ? "<strong>" . e($g["oddelki_imena"]) . "</strong>" : "Vsi razredi" ?></span>
</div>
<?php if (!empty($g["opis"])): ?>
    <p style="margin: 4px 0 0; font-size: 12px; color: #4b5563;"><?= e($g["opis"]) ?></p>
<?php endif; ?>

</div>


<div>

<a
    href="<?= e($g["datoteka_pot"]) ?>"
    target="_blank"
    class="btn small"
>
    Odpri
</a>


<form method="POST"
      class="inline">

<input type="hidden"
       name="action"
       value="izbrisi_gradivo">

<input type="hidden"
       name="id"
       value="<?= $g["id_gradivo"] ?>">


<button
    class="btn red small"
    onclick="return confirm('Res želiš izbrisati gradivo?')"
>
    Izbriši
</button>

</form>

</div>

</div>

<?php endforeach; ?>

<?php if (!$gradiva): ?>
    <p>Ni gradiv.</p>
<?php endif; ?>

</section>


<section class="panel">

<h2>Objavi nalogo</h2>

<form method="POST">

<input type="hidden"
       name="action"
       value="dodaj_nalogo">


<label>
Predmet

<select name="predmet_id" required>

<option value="">
Izberi predmet
</option>

<?php foreach ($predmeti as $p): ?>

<option value="<?= $p["id_predmet"] ?>">
<?= e($p["naziv"]) ?>
</option>

<?php endforeach; ?>

</select>

</label>


<label>
Naslov

<input
    type="text"
    name="naslov"
    required
>

</label>


<label>
Navodila

<textarea
    name="navodila"
    required
></textarea>

</label>


<label>
Rok oddaje

<input
    type="datetime-local"
    name="rok_oddaje"
    required
>

</label>


<button class="btn blue">
Objavi nalogo
</button>

</form>

</section>


<section class="panel full">

<h2>Oddane naloge</h2>

<table>

<tr>

<th>Dijak</th>
<th>Predmet</th>
<th>Naloga</th>
<th>Oddano</th>
<th>Komentar dijaka</th>
<th>Datoteka</th>
<th>Ocena</th>
<th>Komentar učitelja</th>
<th></th>

</tr>


<?php foreach ($oddane as $o): ?>

<tr>

<td>
<?= e($o["priimek"] . " " . $o["ime"]) ?>
</td>

<td>
<?= e($o["predmet"]) ?>
</td>

<td>
<?= e($o["naslov"]) ?>
</td>

<td>
<?= e($o["oddano_ob"]) ?>
</td>

<td>
<?= e($o["komentar_dijaka"]) ?>
</td>

<td>

<a
    href="<?= e($o["datoteka_pot"]) ?>"
    target="_blank"
>
    Odpri
</a>

</td>

<td>
<?= $o["ocena"] !== null ? e($o["ocena"]) : "—" ?>
</td>

<td>
<?= e($o["komentar_ucitelja"]) ?>
</td>

<td>
<details>
    <summary>Oceni</summary>
    <form method="POST">
        <input type="hidden" name="action" value="oceni_oddajo">
        <input type="hidden" name="id_oddana" value="<?= $o["id_oddana"] ?>">

        <input type="number" name="ocena" min="1" max="5"
               value="<?= e($o["ocena"]) ?>"
               placeholder="Ocena (1-5)" required>

        <input type="text" name="komentar_ucitelja"
               value="<?= e($o["komentar_ucitelja"]) ?>"
               placeholder="Komentar">

        <button class="btn small blue">Shrani</button>
    </form>
</details>
</td>

</tr>

<?php endforeach; ?>

</table>

<?php if (!$oddane): ?>
    <p>Ni oddanih nalog.</p>
<?php endif; ?>

</section>


<!-- VPIS DIJAKOV K PREDMETOM -->
<section class="panel full">

<h2>Vpis dijakov k predmetom</h2>

<div class="admin-grid" style="gap: 20px; align-items: start;">

<div>
    <h3>Vpiši dijaka k svojemu predmetu</h3>
    <form method="POST">
        <input type="hidden" name="action" value="povezi_dijaka">

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
            Dijak
            <select name="dijak_id" required>
                <option value="">Izberi dijaka</option>
                <?php foreach ($vsiDijaki as $d): ?>
                <option value="<?= $d["id_uporabnik"] ?>">
                    <?= e($d["priimek"] . " " . $d["ime"] . ($d["oddelek_ime"] ? " (" . $d["oddelek_ime"] . ")" : "")) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button class="btn blue">Vpiši dijaka</button>
    </form>
</div>

<div>
    <h3>Trenutno vpisani dijaki (<?= count($vpisaniDijaki) ?>)</h3>
    <?php if (empty($vpisaniDijaki)): ?>
        <p>Pri vaših predmetih trenutno ni vpisanih dijakov.</p>
    <?php else: ?>
        <div style="max-height: 360px; overflow-y: auto; padding-right: 6px;">
            <?php foreach ($vpisaniDijaki as $vd): ?>
            <div class="list-row">
                <div>
                    <strong><?= e($vd["dijak_priimek"] . " " . $vd["dijak_ime"]) ?></strong>
                    <?php if ($vd["oddelek_ime"]): ?>
                        <span class="muted">(<?= e($vd["oddelek_ime"]) ?>)</span>
                    <?php endif; ?>
                    <br>
                    <small><?= e($vd["predmet_naziv"]) ?></small>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="odstrani_dijaka">
                    <input type="hidden" name="predmet_id" value="<?= $vd["predmet_id"] ?>">
                    <input type="hidden" name="dijak_id" value="<?= $vd["dijak_id"] ?>">
                    <button class="btn red small" onclick="return confirm('Ali res želiš odstraniti dijaka s predmeta?')">
                        Odstrani
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

</section>

</div>

<script>
function toggleVsiOddelki(mainCb) {
    const cbs = document.querySelectorAll('.oddelek-cb');
    if (mainCb.checked) {
        cbs.forEach(cb => { cb.checked = false; });
    }
}

function togglePosamezniOddelek(cb) {
    const mainCb = document.getElementById('vsi_oddelki_cb');
    const checkedCount = document.querySelectorAll('.oddelek-cb:checked').length;
    if (cb.checked && mainCb) {
        mainCb.checked = false;
    } else if (checkedCount === 0 && mainCb) {
        mainCb.checked = true;
    }
}
</script>

<?php pageEnd(); ?>
