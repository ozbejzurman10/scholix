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
                throw new RuntimeException("Datoteka ni veljavna ali je večja od 10 MB.");
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


            $filename = safeFileName(
                $naslov . "." . $ext
            );


            ensureUploadDirectory("uploads/gradivo");
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
// GRADIVA
// ==============================

$stmt = $conn->prepare(
    "SELECT g.*, p.naziv
     FROM Gradivo g
     JOIN Predmet p
       ON p.id_predmet = g.predmet_id
     WHERE g.ucitelj_id = ?
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


<label>
    Datoteka

    <input
        type="file"
        name="datoteka"
        required
    >

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

<small>
<?= e($g["naziv"]) ?>
</small>

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

</div>

<?php pageEnd(); ?>
