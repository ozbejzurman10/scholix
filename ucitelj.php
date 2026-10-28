<?php

require_once "funkcije.php";

requireRole("ucitelj");

$user = getUser();

// Podatki o razredništvu trenutnega učitelja
$stRazrednik = $conn->prepare("SELECT id_oddelek, ime, letnik, program FROM Oddelek WHERE razrednik_id = ? ORDER BY letnik, ime");
$stRazrednik->execute([$user["id_uporabnik"]]);
$mojiRazredi = $stRazrednik->fetchAll();
$mojiRazrediIds = array_map('intval', array_column($mojiRazredi, "id_oddelek"));
$mojiRazrediImena = implode(", ", array_column($mojiRazredi, "ime"));


// ==============================
// AKCIJE
// ==============================

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {

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

            // Dovoljeni razredi za tega učitelja pri tem predmetu iz Urnika
            $stDov = $conn->prepare("
                SELECT DISTINCT o.id_oddelek
                FROM Urnik u
                JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                WHERE u.predmet_id = ? AND u.ucitelj_id = ?
            ");
            $stDov->execute([$predmet, $user["id_uporabnik"]]);
            $dovoljeniOddelkiIds = array_map('intval', $stDov->fetchAll(PDO::FETCH_COLUMN));

            $vsiOddelki = !empty($_POST["vsi_oddelki"]);
            $izbraniOddelki = isset($_POST["oddelki"]) && is_array($_POST["oddelki"])
                ? array_filter(array_map('intval', $_POST["oddelki"]))
                : [];

            if (!empty($dovoljeniOddelkiIds)) {
                if ($vsiOddelki || empty($izbraniOddelki)) {
                    $ciljniOddelki = $dovoljeniOddelkiIds;
                } else {
                    $ciljniOddelki = array_values(array_intersect($izbraniOddelki, $dovoljeniOddelkiIds));
                    if (empty($ciljniOddelki)) {
                        $ciljniOddelki = $dovoljeniOddelkiIds;
                    }
                }

                $stmtGo = $conn->prepare(
                    "INSERT INTO Gradivo_Oddelek (gradivo_id, oddelek_id) VALUES (?, ?)"
                );
                foreach ($ciljniOddelki as $oddId) {
                    $stmtGo->execute([$gradivoId, $oddId]);
                }
            } elseif (!$vsiOddelki && !empty($izbraniOddelki)) {
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

            $nalogaId = (int) $conn->lastInsertId();

            $predmetId = (int) $_POST["predmet_id"];
            // Dovoljeni razredi za tega učitelja pri tem predmetu iz Urnika
            $stDov = $conn->prepare("
                SELECT DISTINCT o.id_oddelek
                FROM Urnik u
                JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                WHERE u.predmet_id = ? AND u.ucitelj_id = ?
            ");
            $stDov->execute([$predmetId, $user["id_uporabnik"]]);
            $dovoljeniOddelkiIds = array_map('intval', $stDov->fetchAll(PDO::FETCH_COLUMN));

            $vsiOddelki = !empty($_POST["vsi_oddelki"]);
            $izbraniOddelki = isset($_POST["oddelki"]) && is_array($_POST["oddelki"])
                ? array_filter(array_map('intval', $_POST["oddelki"]))
                : [];

            if (!empty($dovoljeniOddelkiIds)) {
                if ($vsiOddelki || empty($izbraniOddelki)) {
                    $ciljniOddelki = $dovoljeniOddelkiIds;
                } else {
                    $ciljniOddelki = array_values(array_intersect($izbraniOddelki, $dovoljeniOddelkiIds));
                    if (empty($ciljniOddelki)) {
                        $ciljniOddelki = $dovoljeniOddelkiIds;
                    }
                }

                $stmtNo = $conn->prepare(
                    "INSERT INTO Naloga_Oddelek (naloga_id, oddelek_id) VALUES (?, ?)"
                );
                foreach ($ciljniOddelki as $oddId) {
                    $stmtNo->execute([$nalogaId, $oddId]);
                }
            } elseif (!$vsiOddelki && !empty($izbraniOddelki)) {
                $stmtNo = $conn->prepare(
                    "INSERT INTO Naloga_Oddelek (naloga_id, oddelek_id) VALUES (?, ?)"
                );
                foreach ($izbraniOddelki as $oddId) {
                    $stmtNo->execute([$nalogaId, $oddId]);
                }
            }

            flash("Naloga je bila objavljena.");
        }


        // IZBRIŠI NALOGO
        elseif ($action === "izbrisi_nalogo") {

            $id_naloga = (int) ($_POST["id_naloga"] ?? 0);

            // Preveri, da je naloga profesorjeva in pri predmetu, ki ga dejansko poučuje
            $stmt = $conn->prepare(
                "SELECT n.*
                 FROM Naloga n
                 JOIN Ucitelj_Predmet up ON up.predmet_id = n.predmet_id
                 WHERE n.id_naloga = ?
                 AND n.ucitelj_id = ?
                 AND up.ucitelj_id = ?"
            );
            $stmt->execute([
                $id_naloga,
                $user["id_uporabnik"],
                $user["id_uporabnik"]
            ]);
            $naloga = $stmt->fetch();

            if (!$naloga) {
                throw new RuntimeException("Te naloge ne morete izbrisati ali ne poučujete njenega predmeta.");
            }

            // Izbriši datoteke oddanih nalog
            $stOdd = $conn->prepare("SELECT datoteka_pot FROM Oddana_Naloga WHERE naloga_id = ?");
            $stOdd->execute([$id_naloga]);
            foreach ($stOdd->fetchAll(PDO::FETCH_COLUMN) as $dPot) {
                if ($dPot && file_exists(__DIR__ . "/" . $dPot)) {
                    @unlink(__DIR__ . "/" . $dPot);
                }
            }

            $conn->prepare("DELETE FROM Oddana_Naloga WHERE naloga_id = ?")->execute([$id_naloga]);
            $conn->prepare("DELETE FROM Naloga WHERE id_naloga = ?")->execute([$id_naloga]);

            flash("Naloga »" . $naloga["naslov"] . "« je bila izbrisana.");
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

            // Če gre za Razredno uro, dovoli le vpis dijakov svojega oddelka
            $stmtNaziv = $conn->prepare("SELECT naziv FROM Predmet WHERE id_predmet = ?");
            $stmtNaziv->execute([$predmet_id]);
            $pNaziv = (string) $stmtNaziv->fetchColumn();

            if (mb_stripos($pNaziv, "Razredna") !== false) {
                $stDk = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
                $stDk->execute([$dijak_id]);
                $dOddId = (int) $stDk->fetchColumn();

                if (!in_array($dOddId, $mojiRazrediIds, true)) {
                    throw new RuntimeException("K Razredni uri lahko vpišete le dijake svojega oddelka (" . ($mojiRazrediImena ?: "niste razrednik") . ").");
                }
            } else {
                // Preveri, da dijak obiskuje oddelek, ki ga ta učitelj poučuje pri tem predmetu
                $stDovOdd = $conn->prepare("
                    SELECT DISTINCT oddelek_id FROM Urnik WHERE predmet_id = ? AND ucitelj_id = ?
                ");
                $stDovOdd->execute([$predmet_id, $user["id_uporabnik"]]);
                $dovoljeniOddelki = array_map('intval', $stDovOdd->fetchAll(PDO::FETCH_COLUMN));

                if (!empty($dovoljeniOddelki)) {
                    $stDk = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
                    $stDk->execute([$dijak_id]);
                    $dijakOddelki = (int) $stDk->fetchColumn();

                    if (!in_array($dijakOddelki, $dovoljeniOddelki, true)) {
                        throw new RuntimeException("Tega dijaka ne morete vpisati k temu predmetu, saj ne obiskuje oddelka, ki ga poučujete pri tem predmetu.");
                    }
                }
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

        flash("Napaka: " . userErrorMessage($e));
    }

    $redirectTab = "gradiva";
    if (in_array($action, ["dodaj_nalogo", "izbrisi_nalogo"], true)) {
        $redirectTab = "naloge";
    } elseif ($action === "oceni_oddajo") {
        $redirectTab = "oddane";
    } elseif (in_array($action, ["povezi_dijaka", "odstrani_dijaka"], true)) {
        $redirectTab = "vpis";
    }
    redirect("ucitelj.php?tab=" . $redirectTab);
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
// ODDELKI IN MAPIRANJE PREDMETOV
// ==============================

// Mapiranje: predmet_id -> oddelki, ki jih ta učitelj dejansko poučuje pri tem predmetu
$stmtMap = $conn->prepare("
    SELECT u.predmet_id, o.id_oddelek, o.ime AS oddelek_ime, o.letnik, o.program
    FROM Urnik u
    JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
    WHERE u.ucitelj_id = ?
    GROUP BY u.predmet_id, o.id_oddelek
    ORDER BY o.letnik, o.ime
");
$stmtMap->execute([$user["id_uporabnik"]]);
$mapRows = $stmtMap->fetchAll(PDO::FETCH_ASSOC);

$predmetOddelkiMap = [];
foreach ($mapRows as $r) {
    $predmetOddelkiMap[(int)$r['predmet_id']][] = [
        'id'      => (int)$r['id_oddelek'],
        'ime'     => $r['oddelek_ime'],
        'letnik'  => (int)$r['letnik'],
        'program' => $r['program']
    ];
}

// Oddelki, ki jih ta učitelj dejansko poučuje
$stMojiOddelki = $conn->prepare("
    SELECT DISTINCT o.*
    FROM Urnik u
    JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
    WHERE u.ucitelj_id = ?
    ORDER BY o.letnik, o.ime
");
$stMojiOddelki->execute([$user["id_uporabnik"]]);
$oddelki = $stMojiOddelki->fetchAll();

if (empty($oddelki)) {
    $oddelki = $conn->query("SELECT * FROM Oddelek ORDER BY letnik, ime")->fetchAll();
}

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
// MOJE OBJAVLJENE NALOGE
// ==============================

$stmt = $conn->prepare(
    "SELECT n.*, p.naziv AS predmet,
            GROUP_CONCAT(o.ime ORDER BY o.ime SEPARATOR ', ') AS oddelki_imena,
            (SELECT COUNT(*) FROM Oddana_Naloga od WHERE od.naloga_id = n.id_naloga) AS st_oddaj
     FROM Naloga n
     JOIN Predmet p ON p.id_predmet = n.predmet_id
     JOIN Ucitelj_Predmet up ON up.predmet_id = n.predmet_id AND up.ucitelj_id = ?
     LEFT JOIN Naloga_Oddelek no_rel ON no_rel.naloga_id = n.id_naloga
     LEFT JOIN Oddelek o ON o.id_oddelek = no_rel.oddelek_id
     WHERE n.ucitelj_id = ?
     GROUP BY n.id_naloga
     ORDER BY n.rok_oddaje DESC"
);
$stmt->execute([
    $user["id_uporabnik"],
    $user["id_uporabnik"]
]);
$mojeNaloge = $stmt->fetchAll();


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
    "SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id, o.ime AS oddelek_ime
     FROM Uporabnik u
     LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
     WHERE u.vloga = 'dijak'
     ORDER BY o.ime, u.priimek, u.ime"
)->fetchAll();

// Vpisani dijaki pri profesorjevih predmetih (pri Razredni uri prikazani le dijaki učiteljevega oddelka)
$vpisaniDijaki = [];
if (!empty($predmeti)) {
    $pIds = array_column($predmeti, "id_predmet");
    $placeholders = implode(",", array_fill(0, count($pIds), "?"));
    $mojiRazrediInSql = !empty($mojiRazrediIds) ? implode(",", $mojiRazrediIds) : "0";
    $stmt = $conn->prepare(
        "SELECT dp.predmet_id, dp.dijak_id, p.naziv AS predmet_naziv,
                u.ime AS dijak_ime, u.priimek AS dijak_priimek, o.ime AS oddelek_ime
         FROM Dijak_Predmet dp
         JOIN Predmet p ON p.id_predmet = dp.predmet_id
         JOIN Uporabnik u ON u.id_uporabnik = dp.dijak_id
         LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
         WHERE dp.predmet_id IN ($placeholders)
           AND (
               p.naziv NOT LIKE '%Razredna%'
               OR u.oddelek_id IN ($mojiRazrediInSql)
           )
         ORDER BY p.naziv, o.ime, u.priimek, u.ime"
    );
    $stmt->execute($pIds);
    $vpisaniDijaki = $stmt->fetchAll();
}


pageStart("Učitelj");

?>

<div class="d-flex justify-between align-center flex-wrap gap-3 mb-2">
    <h1 class="m-0">Učiteljski modul</h1>
    <button type="button" class="btn small outline" id="btnToggleAllTeacherTabs" onclick="toggleAllTeacherTabs()">
        Prikaži vse zavihke
    </button>
</div>

<!-- ZAVIHKI ZA HITRI DOSTOP -->
<div class="admin-tab-nav">
    <button type="button" class="admin-tab-btn active" data-tab="gradiva" onclick="openTeacherTab('gradiva', this)">
        Gradiva <span class="tab-badge"><?= count($gradiva) ?></span>
    </button>
    <button type="button" class="admin-tab-btn" data-tab="naloge" onclick="openTeacherTab('naloge', this)">
        Naloge <span class="tab-badge"><?= count($mojeNaloge) ?></span>
    </button>
    <button type="button" class="admin-tab-btn" data-tab="oddane" onclick="openTeacherTab('oddane', this)">
        Oddane naloge <span class="tab-badge"><?= count($oddane) ?></span>
    </button>
    <button type="button" class="admin-tab-btn" data-tab="vpis" onclick="openTeacherTab('vpis', this)">
        Vpis dijakov k predmetom <span class="tab-badge"><?= count($vpisaniDijaki) ?></span>
    </button>
</div>


<!-- ZAVIHEK 1: GRADIVA -->
<div id="tab-gradiva" class="tab-content active">
<div class="admin-grid">


<section class="panel">

<h2>Naloži gradivo</h2>

<form method="POST"
      enctype="multipart/form-data"
      class="d-flex flex-column gap-3">

<input type="hidden"
       name="action"
       value="dodaj_gradivo">


<label>
    Predmet

    <select name="predmet_id" id="gradivo_predmet_id" required onchange="posodobiOddelkeGradivo(this.value)">

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


<div class="field-block my-2">
    <label class="form-label-block">
        Ciljni razredi / oddelki za izbrani predmet
    </label>
    <div id="kontejner-oddelki-gradivo" class="classes-selection-box">
        <div class="classes-empty-hint">
            Najprej izberite predmet zgoraj, da se prikažejo razredi, ki jih poučujete pri tem predmetu.
        </div>
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
    <small class="form-hint">Podprte so PowerPoint (.pptx, .ppt), PDF, Word, Excel in druge datoteke do 40 MB.</small>

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

<div class="text-xs text-muted mt-1">
    <span>Predmet: <?= e($g["naziv"]) ?></span>
    &bull;
    <span>Razred: <?= !empty($g["oddelki_imena"]) ? "<strong>" . e($g["oddelki_imena"]) . "</strong>" : "Vsi razredi" ?></span>
</div>
<?php if (!empty($g["opis"])): ?>
    <p class="text-xs text-secondary mt-1 mb-0"><?= e($g["opis"]) ?></p>
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
    class="gumb-odstrani"
    onclick="return confirm('Res želiš izbrisati gradivo?')"
>
    Izbriši
</button>

</form>

</div>

</div>

<?php endforeach; ?>

<?php if (!$gradiva): ?>
    <p class="prazno-stanje">Ni gradiv.</p>
<?php endif; ?>

</section>

</div>
</div>


<!-- ZAVIHEK 2: NALOGE -->
<div id="tab-naloge" class="tab-content">
<div class="admin-grid">

<section class="panel">

<h2>Objavi nalogo</h2>

<form method="POST"
      class="d-flex flex-column gap-3">

<input type="hidden"
       name="action"
       value="dodaj_nalogo">

<label>
    Predmet

    <select name="predmet_id" id="naloga_predmet_id" required onchange="posodobiOddelkeNaloga(this.value)">

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
    Naslov naloge

    <input
        type="text"
        name="naslov"
        required
        placeholder="Npr. Domače branje, Vaja 3..."
    >

</label>

<label>
    Navodila

    <textarea name="navodila" placeholder="Kratka navodila za oddajo naloge..."></textarea>

</label>

<label>
    Rok oddaje

    <input
        type="datetime-local"
        name="rok_oddaje"
        required
    >

</label>

<div class="field-block my-2">
    <label class="form-label-block">
        Ciljni razredi / oddelki za izbrani predmet
    </label>
    <div id="kontejner-oddelki-naloga" class="classes-selection-box">
        <div class="classes-empty-hint">
            Najprej izberite predmet zgoraj, da se prikažejo razredi, ki jih poučujete pri tem predmetu.
        </div>
    </div>
</div>

<button class="btn blue">
    Objavi nalogo
</button>

</form>

</section>


<section class="panel">

<h2>Moje objavljene naloge</h2>

<?php foreach ($mojeNaloge as $n): ?>

<div class="list-row">

<div>

<strong>
<?= e($n["naslov"]) ?>
</strong>

<div class="text-xs text-muted mt-1">
    <span>Predmet: <?= e($n["predmet"]) ?></span>
    &bull;
    <span>Rok: <strong><?= date("d.m.Y H:i", strtotime($n["rok_oddaje"])) ?></strong></span>
    &bull;
    <span>Razred: <?= !empty($n["oddelki_imena"]) ? "<strong>" . e($n["oddelki_imena"]) . "</strong>" : "Vsi razredi" ?></span>
    &bull;
    <span>Oddaje: <strong><?= (int)$n["st_oddaj"] ?></strong></span>
</div>
<?php if (!empty($n["navodila"])): ?>
    <p class="text-xs text-secondary mt-1 mb-0"><?= e($n["navodila"]) ?></p>
<?php endif; ?>

</div>

<div>

<form method="POST"
      class="inline">

<input type="hidden"
       name="action"
       value="izbrisi_nalogo">

<input type="hidden"
       name="id_naloga"
       value="<?= $n["id_naloga"] ?>">

<button
    class="gumb-odstrani"
    onclick="return confirm('Res želiš izbrisati nalogo? Izbrisane bodo tudi vse povezane oddaje.')"
>
    Izbriši
</button>

</form>

</div>

</div>

<?php endforeach; ?>

<?php if (!$mojeNaloge): ?>
    <p class="prazno-stanje">Nimate še objavljenih nalog.</p>
<?php endif; ?>

</section>

</div>
</div>


<!-- ZAVIHEK 2: ODDANE NALOGE -->
<div id="tab-oddane" class="tab-content">

<section class="panel full">

<h2>Oddane naloge</h2>

<div class="tabela-ovoj"><table>

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

</table></div>

<?php if (!$oddane): ?>
    <p class="prazno-stanje">Ni oddanih nalog.</p>
<?php endif; ?>

</section>

</div>


<!-- ZAVIHEK 4: VPIS DIJAKOV K PREDMETOM -->
<div id="tab-vpis" class="tab-content">
<section class="panel full enrollment-panel">

<div class="enrollment-header">
    <div>
        <h2 class="enrollment-header-title">Vpis dijakov k predmetom</h2>
        <p class="enrollment-header-subtitle">
            Dodelite dijake k svojim predmetom ali uredite obstoječe vpise v razpredelnici.
        </p>
    </div>
    <span class="badge-count-pill">
        <span id="st_vpisanih_prikaz"><?= count($vpisaniDijaki) ?></span> vpisanih
    </span>
</div>

<!-- ZGORNJA VRSTICA: Hitri vpis dijaka -->
<form method="POST" class="enrollment-top-bar">
    <input type="hidden" name="action" value="povezi_dijaka">

    <div class="enrollment-top-field">
        <label class="enrollment-label" for="izbira_prof_predmet">Predmet</label>
        <select name="predmet_id" id="izbira_prof_predmet" class="enrollment-select" required onchange="posodobiIzbiroDijakov()">
            <option value="">-- Izberite predmet --</option>
            <?php foreach ($predmeti as $p): ?>
                <?php if (mb_stripos($p["naziv"], "Razredna") !== false) continue; ?>
                <option value="<?= (int)$p["id_predmet"] ?>">
                    <?= e($p["naziv"]) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="enrollment-top-field">
        <label class="enrollment-label" for="izbira_prof_dijak">Dijak</label>
        <select name="dijak_id" id="izbira_prof_dijak" class="enrollment-select" required>
            <option value="">-- Izberite dijaka --</option>
            <?php foreach ($vsiDijaki as $d): ?>
                <?php
                $jeMojRazred = !empty($d["oddelek_id"]) && in_array((int)$d["oddelek_id"], $mojiRazrediIds, true);
                ?>
                <option value="<?= (int)$d["id_uporabnik"] ?>"
                        data-oddelek-id="<?= (int)$d["oddelek_id"] ?>"
                        data-je-moj="<?= $jeMojRazred ? '1' : '0' ?>">
                    <?= e($d["priimek"] . " " . $d["ime"] . ($d["oddelek_ime"] ? " (" . $d["oddelek_ime"] . ")" : "")) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="enrollment-top-btn-wrap">
        <button type="submit" class="btn blue btn-enroll-inline">
            + Vpiši dijaka k predmetu
        </button>
    </div>
</form>

<!-- ORODNA VRSTICA ZA FILTRIRANJE TABELE -->
<div class="enrollment-toolbar">
    <input
        type="text"
        id="filter_vpisanih_search"
        placeholder="Išči dijaka, razred ali predmet..."
        class="enrollment-search-box"
        oninput="filtrirajVpisaneDijake()"
    >

    <?php if (count($predmeti) > 1): ?>
        <select id="filter_predmet_vpisanih" onchange="filtrirajVpisaneDijake()" class="enrollment-filter-select">
            <option value="0">Vsi predmeti (<?= count($vpisaniDijaki) ?>)</option>
            <?php foreach ($predmeti as $p): ?>
                <?php if (mb_stripos($p["naziv"], "Razredna") !== false) continue; ?>
                <?php
                $stZaPredmet = count(array_filter($vpisaniDijaki, fn($x) => (int)$x["predmet_id"] === (int)$p["id_predmet"]));
                ?>
                <option value="<?= (int)$p["id_predmet"] ?>"><?= e($p["naziv"]) ?> (<?= $stZaPredmet ?>)</option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>

<?php if (empty($vpisaniDijaki)): ?>
    <div class="prazno-stanje">
        <p class="m-0">Pri vaših predmetih trenutno ni vpisanih dijakov.</p>
    </div>
<?php else: ?>
    <div class="tabela-ovoj enrollment-table-scroll">
        <table id="tabela_vpisanih_dijakov">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th>Dijak</th>
                    <th>Razred</th>
                    <th>Predmet</th>
                    <th class="col-center">Dejanje</th>
                </tr>
            </thead>
            <tbody>
                <?php $zapVp = 1; ?>
                <?php foreach ($vpisaniDijaki as $vd): ?>
                    <?php
                    $searchText = mb_strtolower($vd["dijak_priimek"] . " " . $vd["dijak_ime"] . " " . ($vd["oddelek_ime"] ?? "") . " " . $vd["predmet_naziv"]);
                    ?>
                    <tr class="vpisani-row" data-predmet-id="<?= (int)$vd["predmet_id"] ?>" data-search="<?= e($searchText) ?>">
                        <td class="col-num text-muted font-bold vpisani-row-idx"><?= $zapVp++ ?>.</td>
                        <td>
                            <strong><?= e($vd["dijak_priimek"] . " " . $vd["dijak_ime"]) ?></strong>
                        </td>
                        <td>
                            <?php if ($vd["oddelek_ime"]): ?>
                                <span class="badge-class-chip"><?= e($vd["oddelek_ime"]) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($vd["predmet_naziv"]) ?>
                        </td>
                        <td class="col-center">
                            <form method="POST" class="remove-student-form">
                                <input type="hidden" name="action" value="odstrani_dijaka">
                                <input type="hidden" name="predmet_id" value="<?= (int)$vd["predmet_id"] ?>">
                                <input type="hidden" name="dijak_id" value="<?= (int)$vd["dijak_id"] ?>">
                                <button type="submit" class="gumb-odstrani" onclick="return confirm('Ali res želiš odstraniti dijaka s predmeta?')">
                                    Odstrani
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="no_matching_students" class="empty-filter-state d-none mt-2">
        Noben vpisani dijak ne ustreza izbranemu filtru.
    </div>
<?php endif; ?>

</section>

</div>

<script>
const predmetOddelkiMap = <?= json_encode($predmetOddelkiMap, JSON_UNESCAPED_UNICODE) ?>;

function posodobiOddelkeGradivo(predmetId) {
    const wrap = document.getElementById('kontejner-oddelki-gradivo');
    if (!wrap) return;

    const pid = parseInt(predmetId, 10);
    const classes = (pid && predmetOddelkiMap[pid]) ? predmetOddelkiMap[pid] : [];

    if (!pid || classes.length === 0) {
        wrap.innerHTML = '<div class="classes-empty-hint">Najprej izberite predmet zgoraj, da se prikažejo razredi, ki jih poučujete pri tem predmetu.</div>';
        return;
    }

    const classNames = classes.map(c => c.ime).join(', ');

    let html = `
        <label class="classes-header">
            <input type="checkbox" id="vsi_oddelki_gradivo_cb" name="vsi_oddelki" value="1" checked onchange="toggleVsiOddelkiGradivo(this)">
            Vsi moji razredi pri tem predmetu (${classNames})
        </label>
        <div class="border-top pt-2">
            <div class="classes-chip-title">
                Ali izberite posamezne razrede:
            </div>
            <div class="classes-chip-list">
    `;

    classes.forEach(c => {
        html += `
            <label class="classes-chip-item">
                <input type="checkbox" name="oddelki[]" value="${c.id}" class="oddelek-gradivo-cb" onchange="togglePosamezniOddelekGradivo(this)">
                ${c.ime} <span class="text-xs font-normal text-muted">(${c.letnik}. letnik)</span>
            </label>
        `;
    });

    html += `
            </div>
        </div>
    `;

    wrap.innerHTML = html;
}

function toggleVsiOddelkiGradivo(mainCb) {
    if (mainCb.checked) {
        document.querySelectorAll('.oddelek-gradivo-cb').forEach(cb => cb.checked = false);
    }
}

function togglePosamezniOddelekGradivo(cb) {
    const mainCb = document.getElementById('vsi_oddelki_gradivo_cb');
    const checkedCount = document.querySelectorAll('.oddelek-gradivo-cb:checked').length;
    if (cb.checked && mainCb) {
        mainCb.checked = false;
    } else if (checkedCount === 0 && mainCb) {
        mainCb.checked = true;
    }
}

function posodobiOddelkeNaloga(predmetId) {
    const wrap = document.getElementById('kontejner-oddelki-naloga');
    if (!wrap) return;

    const pid = parseInt(predmetId, 10);
    const classes = (pid && predmetOddelkiMap[pid]) ? predmetOddelkiMap[pid] : [];

    if (!pid || classes.length === 0) {
        wrap.innerHTML = '<div class="classes-empty-hint">Najprej izberite predmet zgoraj, da se prikažejo razredi, ki jih poučujete pri tem predmetu.</div>';
        return;
    }

    const classNames = classes.map(c => c.ime).join(', ');

    let html = `
        <label class="classes-header">
            <input type="checkbox" id="vsi_oddelki_naloga_cb" name="vsi_oddelki" value="1" checked onchange="toggleVsiOddelkiNaloga(this)">
            Vsi moji razredi pri tem predmetu (${classNames})
        </label>
        <div class="border-top pt-2">
            <div class="classes-chip-title">
                Ali izberite posamezne razrede:
            </div>
            <div class="classes-chip-list">
    `;

    classes.forEach(c => {
        html += `
            <label class="classes-chip-item">
                <input type="checkbox" name="oddelki[]" value="${c.id}" class="oddelek-naloga-cb" onchange="togglePosamezniOddelekNaloga(this)">
                ${c.ime} <span class="text-xs font-normal text-muted">(${c.letnik}. letnik)</span>
            </label>
        `;
    });

    html += `
            </div>
        </div>
    `;

    wrap.innerHTML = html;
}

function toggleVsiOddelkiNaloga(mainCb) {
    if (mainCb.checked) {
        document.querySelectorAll('.oddelek-naloga-cb').forEach(cb => cb.checked = false);
    }
}

function togglePosamezniOddelekNaloga(cb) {
    const mainCb = document.getElementById('vsi_oddelki_naloga_cb');
    const checkedCount = document.querySelectorAll('.oddelek-naloga-cb:checked').length;
    if (cb.checked && mainCb) {
        mainCb.checked = false;
    } else if (checkedCount === 0 && mainCb) {
        mainCb.checked = true;
    }
}

function posodobiIzbiroDijakov() {
    const selP = document.getElementById('izbira_prof_predmet');
    const selD = document.getElementById('izbira_prof_dijak');
    const opomba = document.getElementById('razredna_ura_opomba');
    if (!selP || !selD) return;

    const opt = selP.options[selP.selectedIndex];
    const isRazredna = opt && opt.getAttribute('data-is-razredna') === '1';
    const pid = parseInt(selP.value, 10);
    const dovoljeniOdd = (pid && predmetOddelkiMap[pid]) ? predmetOddelkiMap[pid].map(x => x.id) : [];

    if (opomba) {
        opomba.classList.toggle('d-none', !isRazredna);
    }

    Array.from(selD.options).forEach(optD => {
        if (!optD.value) return;
        const oddId = parseInt(optD.getAttribute('data-oddelek-id') || '0', 10);
        let show = true;
        if (isRazredna) {
            show = (optD.getAttribute('data-je-moj') === '1');
        } else if (dovoljeniOdd.length > 0) {
            show = dovoljeniOdd.includes(oddId);
        }
        optD.hidden = !show;
        optD.disabled = !show;
    });

    const curSel = selD.selectedOptions[0];
    if (curSel && curSel.hidden) {
        selD.value = '';
    }
}

function filtrirajVpisaneDijake() {
    const selFilter = document.getElementById('filter_predmet_vpisanih');
    const searchFilter = document.getElementById('filter_vpisanih_search');
    const predmetId = selFilter ? selFilter.value : '0';
    const query = searchFilter ? searchFilter.value.trim().toLowerCase() : '';

    const rows = document.querySelectorAll('.vpisani-row');
    const noMatch = document.getElementById('no_matching_students');
    let visible = 0;

    rows.forEach(r => {
        const rowPid = r.getAttribute('data-predmet-id');
        const rowSearch = (r.getAttribute('data-search') || '').toLowerCase();

        const matchPid = (predmetId === '0' || rowPid === String(predmetId));
        const matchQuery = (!query || rowSearch.includes(query));

        if (matchPid && matchQuery) {
            r.classList.remove('d-none');
            visible++;
            const idxCell = r.querySelector('.vpisani-row-idx');
            if (idxCell) idxCell.textContent = visible + '.';
        } else {
            r.classList.add('d-none');
        }
    });

    const stEl = document.getElementById('st_vpisanih_prikaz');
    if (stEl) stEl.textContent = visible;
    if (noMatch) {
        noMatch.classList.toggle('d-none', visible > 0 || rows.length === 0);
    }
}

let allTeacherTabsShown = false;

function openTeacherTab(tabId, btnEl) {
    allTeacherTabsShown = false;
    const toggleBtn = document.getElementById('btnToggleAllTeacherTabs');
    if (toggleBtn) toggleBtn.textContent = 'Prikaži vse zavihke';

    document.querySelectorAll('.tab-content').forEach(el => {
        el.classList.remove('active');
    });
    document.querySelectorAll('.admin-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    const target = document.getElementById('tab-' + tabId);
    if (target) target.classList.add('active');
    if (btnEl) btnEl.classList.add('active');

    if (history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        history.replaceState(null, '', url);
    }
}

function toggleAllTeacherTabs() {
    allTeacherTabsShown = !allTeacherTabsShown;
    const toggleBtn = document.getElementById('btnToggleAllTeacherTabs');

    if (allTeacherTabsShown) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('active'));
        document.querySelectorAll('.admin-tab-btn').forEach(btn => btn.classList.add('active'));
        if (toggleBtn) toggleBtn.textContent = 'Prikaži po zavihkih';
    } else {
        openTeacherTab('gradiva', document.querySelector('.admin-tab-btn[data-tab="gradiva"]'));
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab && document.getElementById('tab-' + tab)) {
        const btn = document.querySelector('.admin-tab-btn[data-tab="' + tab + '"]');
        openTeacherTab(tab, btn);
    }

    const selG = document.getElementById('gradivo_predmet_id');
    if (selG && selG.value) {
        posodobiOddelkeGradivo(selG.value);
    }
    const selN = document.getElementById('naloga_predmet_id');
    if (selN && selN.value) {
        posodobiOddelkeNaloga(selN.value);
    }
    posodobiIzbiroDijakov();
});
</script>

<?php pageEnd(); ?>
