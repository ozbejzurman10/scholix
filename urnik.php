<?php

require_once "functions.php";

requireLogin();

$user = getUser();

$days = [
    "Ponedeljek",
    "Torek",
    "Sreda",
    "Četrtek",
    "Petek"
];


// ==============================
// IZBRAN TEDEN (ponedeljek v tednu)
// ==============================

$tedenParam = $_GET["teden"] ?? "";
$tedenDatum = DateTime::createFromFormat("Y-m-d", $tedenParam);

if (!$tedenDatum || $tedenDatum->format("Y-m-d") !== $tedenParam) {
    $tedenDatum = new DateTime("today");
}

$ponedeljek = (clone $tedenDatum)->modify("monday this week");

$datumiTedna = [];
foreach ($days as $i => $day) {
    $datumiTedna[$day] = (clone $ponedeljek)->modify("+$i day");
}

$tedenPrej = (clone $ponedeljek)->modify("-7 day")->format("Y-m-d");
$tedenNaprej = (clone $ponedeljek)->modify("+7 day")->format("Y-m-d");
$tedenTa = (new DateTime("today"))->modify("monday this week")->format("Y-m-d");
$tedenVrednost = $ponedeljek->format("Y-m-d");


// ==============================
// AKCIJE ADMINISTRATORJA
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireRole("admin");

    $redirectUrl = "urnik.php";

    try {

        $action = $_POST["action"] ?? "";

        // IZBRIŠI URO
        if ($action === "izbrisi") {

            $stmt = $conn->prepare(
                "DELETE FROM Urnik
                 WHERE id_urnik = ?"
            );

            $stmt->execute([
                (int) $_POST["id"]
            ]);

            flash("Urnik je bil posodobljen.");
        }

        // DODAJ ALI UREDI URO
        elseif (in_array($action, ["dodaj", "uredi"], true)) {

            $vrednosti = [
                (int) $_POST["predmet_id"],
                (int) $_POST["ucitelj_id"],
                $_POST["dan"],
                (int) $_POST["ura"],
                trim($_POST["ucilnica"]),
                ($_POST["oddelek_id"] ?? "") !== "" ? (int) $_POST["oddelek_id"] : null
            ];

            // Preverjanje dneva
            if (!in_array($vrednosti[2], $days, true)) {
                throw new RuntimeException();
            }

            // Preverjanje ure
            if ($vrednosti[3] < 0 || $vrednosti[3] > 12) {
                throw new RuntimeException();
            }


            // DODAJ URO
            if ($action === "dodaj") {

                $stmt = $conn->prepare(
                    "INSERT INTO Urnik
                    (
                        predmet_id,
                        ucitelj_id,
                        dan_v_tednu,
                        ura,
                        ucilnica,
                        oddelek_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute($vrednosti);
            }

            // UREDI URO
            else {

                $vrednosti[] = (int) $_POST["id"];

                $stmt = $conn->prepare(
                    "UPDATE Urnik
                     SET predmet_id = ?,
                         ucitelj_id = ?,
                         dan_v_tednu = ?,
                         ura = ?,
                         ucilnica = ?,
                         oddelek_id = ?
                     WHERE id_urnik = ?"
                );

                $stmt->execute($vrednosti);
            }

            flash("Urnik je bil posodobljen.");

        }

        // DODAJ DAN BREZ POUKA
        elseif ($action === "dodaj_prosto") {

            $datum = $_POST["datum"] ?? "";
            $datumObj = DateTime::createFromFormat("Y-m-d", $datum);

            if (!$datumObj || $datumObj->format("Y-m-d") !== $datum) {
                throw new RuntimeException("Vnesite veljaven datum.");
            }

            $razlog = trim($_POST["razlog"] ?? "");

            if ($razlog === "" || mb_strlen($razlog) > 150) {
                throw new RuntimeException("Vnesite razlog (največ 150 znakov), npr. Športni dan.");
            }

            $vsi = !empty($_POST["vsi_oddelki"]);
            $izbrani = isset($_POST["oddelki"]) && is_array($_POST["oddelki"])
                ? array_values(array_unique(array_filter(array_map("intval", $_POST["oddelki"]))))
                : [];

            if (!$vsi && !$izbrani) {
                throw new RuntimeException("Izberite vse razrede ali vsaj en razred.");
            }

            $stmtIns = $conn->prepare(
                "INSERT INTO Dan_Brez_Pouka (datum, razlog, oddelek_id) VALUES (?, ?, ?)"
            );

            $conn->beginTransaction();

            if ($vsi) {
                $stmtIns->execute([$datum, $razlog, null]);
            } else {
                foreach ($izbrani as $oddId) {
                    $stmtIns->execute([$datum, $razlog, $oddId]);
                }
            }

            $conn->commit();

            $redirectUrl = "urnik.php?teden=" . (clone $datumObj)->modify("monday this week")->format("Y-m-d");

            flash("Dan brez pouka je bil dodan.");
        }

        // VRNI POUK (izbriši dan brez pouka)
        elseif ($action === "izbrisi_prosto") {

            $ids = array_values(array_filter(array_map("intval", explode(",", $_POST["ids"] ?? ""))));

            if (!$ids) {
                throw new RuntimeException("Zapis ni bil najden.");
            }

            $placeholders = implode(",", array_fill(0, count($ids), "?"));

            $stmt = $conn->prepare(
                "DELETE FROM Dan_Brez_Pouka WHERE id_dan IN ($placeholders)"
            );

            $stmt->execute($ids);

            flash("Pouk je bil ponovno vrnjen v urnik.");
        }

        // ODPADLA URA (samo za izbran datum)
        elseif ($action === "odpade_ura") {

            $urnikId = (int) ($_POST["urnik_id"] ?? 0);
            $datum = $_POST["datum"] ?? "";
            $datumObj = DateTime::createFromFormat("Y-m-d", $datum);

            if (!$datumObj || $datumObj->format("Y-m-d") !== $datum) {
                throw new RuntimeException("Vnesite veljaven datum.");
            }

            $stmt = $conn->prepare("SELECT * FROM Urnik WHERE id_urnik = ?");
            $stmt->execute([$urnikId]);
            $ura = $stmt->fetch();

            if (!$ura) {
                throw new RuntimeException("Ura ni bila najdena.");
            }

            // Datum mora ustrezati dnevu te ure
            $dnevIndex = (int) $datumObj->format("N") - 1;
            if (!isset($days[$dnevIndex]) || $days[$dnevIndex] !== $ura["dan_v_tednu"]) {
                throw new RuntimeException("Datum se ne ujema z dnevom te ure.");
            }

            $razlog = trim($_POST["razlog"] ?? "");
            if (mb_strlen($razlog) > 200) {
                throw new RuntimeException("Razlog je predolg (največ 200 znakov).");
            }

            $conn->prepare(
                "DELETE FROM Sprememba_Urnika WHERE tip = 'odpade' AND urnik_id = ? AND datum = ?"
            )->execute([$urnikId, $datum]);

            $conn->prepare(
                "INSERT INTO Sprememba_Urnika (datum, tip, urnik_id, opomba) VALUES (?, 'odpade', ?, ?)"
            )->execute([$datum, $urnikId, $razlog !== "" ? $razlog : null]);

            $redirectUrl = "urnik.php?teden=" . (clone $datumObj)->modify("monday this week")->format("Y-m-d");

            flash("Ura je za izbran datum odpadla.");
        }

        // DODATNA / NADOMESTNA URA (enkratno ali trajno)
        elseif ($action === "dodaj_spremembo") {

            $obseg = $_POST["obseg"] ?? "datum";
            $datum = $_POST["datum"] ?? "";
            $datumObj = DateTime::createFromFormat("Y-m-d", $datum);

            if (!$datumObj || $datumObj->format("Y-m-d") !== $datum) {
                throw new RuntimeException("Vnesite veljaven datum.");
            }

            $dnevIndex = (int) $datumObj->format("N") - 1;
            if (!isset($days[$dnevIndex])) {
                throw new RuntimeException("Izberite delovni dan (od ponedeljka do petka).");
            }

            $oddelekId = (int) ($_POST["oddelek_id"] ?? 0);
            $predmetId = (int) ($_POST["predmet_id"] ?? 0);
            $uciteljId = (int) ($_POST["ucitelj_id"] ?? 0);
            $uraSt = (int) ($_POST["ura"] ?? -1);
            $ucilnica = trim($_POST["ucilnica"] ?? "");

            if ($oddelekId <= 0 || $predmetId <= 0 || $uciteljId <= 0) {
                throw new RuntimeException("Izberite razred, predmet in učitelja.");
            }

            if ($uraSt < 0 || $uraSt > 12) {
                throw new RuntimeException("Ura mora biti med 0 in 12.");
            }

            if ($obseg === "trajno") {

                $conn->prepare(
                    "INSERT INTO Urnik (predmet_id, ucitelj_id, dan_v_tednu, ura, ucilnica, oddelek_id)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$predmetId, $uciteljId, $days[$dnevIndex], $uraSt, $ucilnica, $oddelekId]);

                flash("Ura je bila trajno dodana v tedenski urnik (" . $days[$dnevIndex] . ", " . $uraSt . ". ura).");

            } else {

                $vrsta = $_POST["vrsta"] ?? "dodatna";
                if (!in_array($vrsta, ["dodatna", "nadomescanje", "nadomestna"], true)) {
                    $vrsta = "dodatna";
                }

                $opomba = trim($_POST["opomba"] ?? "");
                if (mb_strlen($opomba) > 200) {
                    throw new RuntimeException("Opomba je predolga (največ 200 znakov).");
                }

                $conn->prepare(
                    "INSERT INTO Sprememba_Urnika
                     (datum, tip, vrsta, oddelek_id, ura, predmet_id, ucitelj_id, ucilnica, opomba)
                     VALUES (?, 'dodatna', ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $datum, $vrsta, $oddelekId, $uraSt, $predmetId, $uciteljId,
                    $ucilnica !== "" ? $ucilnica : null,
                    $opomba !== "" ? $opomba : null
                ]);

                flash("Sprememba urnika je bila dodana za izbran datum.");
            }

            $redirectUrl = "urnik.php?teden=" . (clone $datumObj)->modify("monday this week")->format("Y-m-d");
        }

        // ODSTRANI ENKRATNO SPREMEMBO (vrni uro / odstrani dodatno uro)
        elseif ($action === "izbrisi_spremembo") {

            $id = (int) ($_POST["id"] ?? 0);

            $conn->prepare("DELETE FROM Sprememba_Urnika WHERE id_sprememba = ?")->execute([$id]);

            if (!empty($_POST["nazaj_teden"]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST["nazaj_teden"])) {
                $redirectUrl = "urnik.php?teden=" . $_POST["nazaj_teden"];
            }

            flash("Sprememba urnika je bila odstranjena.");
        }

        else {

            throw new RuntimeException();
        }

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $sporocilo = ($e instanceof RuntimeException && $e->getMessage() !== "")
            ? $e->getMessage()
            : "Napaka pri urejanju urnika.";

        flash($sporocilo);
    }

    redirect($redirectUrl);
}


// ==============================
// PRIDOBIVANJE URNIKA
// ==============================

$sql = "
    SELECT
        ur.*,
        p.naziv,
        u.ime,
        u.priimek,
        o.ime AS oddelek

    FROM Urnik ur

    JOIN Predmet p
        ON p.id_predmet = ur.predmet_id

    JOIN Uporabnik u
        ON u.id_uporabnik = ur.ucitelj_id

    LEFT JOIN Oddelek o
        ON o.id_oddelek = ur.oddelek_id
";

$where = [];
$params = [];

// Seznam oddelkov (za filter in obrazec)
$oddelki = $conn->query(
    "SELECT * FROM Oddelek ORDER BY letnik, ime"
)->fetchAll();

$izbranOddelek = (int) ($_GET["oddelek"] ?? 0);


// UČITELJ
if ($user["vloga"] === "ucitelj") {

    $where[] = "ur.ucitelj_id = ?";
    $params[] = $user["id_uporabnik"];
}


// DIJAK
elseif ($user["vloga"] === "dijak") {

    // Dijak z oddelkom vidi urnik svojega oddelka
    if (!empty($user["oddelek_id"])) {

        $where[] = "ur.oddelek_id = ?";
        $params[] = $user["oddelek_id"];

    } else {

        $sql .= "
            JOIN Dijak_Predmet dp
                ON dp.predmet_id = ur.predmet_id
        ";

        $where[] = "dp.dijak_id = ?";
        $params[] = $user["id_uporabnik"];
    }
}


// FILTER PO ODDELKU (admin, učitelj)
if ($user["vloga"] !== "dijak" && $izbranOddelek > 0) {

    $where[] = "ur.oddelek_id = ?";
    $params[] = $izbranOddelek;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

// RAZVRŠČANJE PO DNEVU IN URI

$sql .= "
    ORDER BY
        FIELD(
            ur.dan_v_tednu,
            'Ponedeljek',
            'Torek',
            'Sreda',
            'Četrtek',
            'Petek'
        ),
        ur.ura
";


$stmt = $conn->prepare($sql);

$stmt->execute($params);

$entries = $stmt->fetchAll();


// ==============================
// DNEVI BREZ POUKA V IZBRANEM TEDNU
// ==============================

$prostiTedna = [];

$stmt = $conn->prepare(
    "SELECT d.*, o.ime AS oddelek_ime
     FROM Dan_Brez_Pouka d
     LEFT JOIN Oddelek o ON o.id_oddelek = d.oddelek_id
     WHERE d.datum BETWEEN ? AND ?
     ORDER BY d.datum, d.razlog, o.ime"
);

$stmt->execute([
    $ponedeljek->format("Y-m-d"),
    $datumiTedna["Petek"]->format("Y-m-d")
]);

foreach ($stmt->fetchAll() as $r) {
    $prostiTedna[$r["datum"]][] = $r;
}

// Kateri oddelek je "v kontekstu" (dijak: svoj, ostali: izbrani filter)
$kontekstOddelek = $user["vloga"] === "dijak"
    ? (int) ($user["oddelek_id"] ?? 0)
    : $izbranOddelek;

// Ali dan brez pouka velja za določen oddelek (null = ura brez oddelka)
function prostDanRazlog(array $vrstice, $oddelekId)
{
    foreach ($vrstice as $r) {
        if ($r["oddelek_id"] === null || ($oddelekId !== null && (int) $r["oddelek_id"] === (int) $oddelekId)) {
            return $r["razlog"];
        }
    }

    return null;
}

// Opombe v glavi stolpca za vsak dan
function opombeDneva(array $vrstice, $kontekst, $jeDijak)
{
    $opombe = [];

    foreach ($vrstice as $r) {

        $velja = $r["oddelek_id"] === null || ((int) $r["oddelek_id"] === (int) $kontekst);

        if ($kontekst > 0 || $jeDijak) {
            if (!$velja) {
                continue;
            }
            $opombe[$r["razlog"]] = $opombe[$r["razlog"]] ?? [];
        } else {
            $opombe[$r["razlog"]] = $opombe[$r["razlog"]] ?? [];
            $opombe[$r["razlog"]][] = $r["oddelek_id"] === null ? "vsi razredi" : $r["oddelek_ime"];
        }
    }

    return $opombe;
}


// ==============================
// ENKRATNE SPREMEMBE V IZBRANEM TEDNU
// ==============================

$odpadleUre = [];   // [datum][urnik_id] => ["id" => ..., "razlog" => ...]
$dodatneUre = [];   // seznam dodatnih / nadomestnih ur

$stmt = $conn->prepare(
    "SELECT s.*,
            pr.naziv AS predmet_naziv,
            uc.ime AS ucitelj_ime,
            uc.priimek AS ucitelj_priimek,
            od.ime AS oddelek_ime
     FROM Sprememba_Urnika s
     LEFT JOIN Predmet pr ON pr.id_predmet = s.predmet_id
     LEFT JOIN Uporabnik uc ON uc.id_uporabnik = s.ucitelj_id
     LEFT JOIN Oddelek od ON od.id_oddelek = s.oddelek_id
     WHERE s.datum BETWEEN ? AND ?
     ORDER BY s.datum, s.ura"
);

$stmt->execute([
    $ponedeljek->format("Y-m-d"),
    $datumiTedna["Petek"]->format("Y-m-d")
]);

foreach ($stmt->fetchAll() as $s) {

    if ($s["tip"] === "odpade") {
        $odpadleUre[$s["datum"]][(int) $s["urnik_id"]] = [
            "id" => (int) $s["id_sprememba"],
            "razlog" => $s["opomba"]
        ];
        continue;
    }

    // Dodatna ura: prikaži samo ciljni publiki
    if ($user["vloga"] === "dijak") {
        if (empty($user["oddelek_id"]) || (int) $s["oddelek_id"] !== (int) $user["oddelek_id"]) {
            continue;
        }
    } else {
        if ($user["vloga"] === "ucitelj" && (int) $s["ucitelj_id"] !== (int) $user["id_uporabnik"]) {
            continue;
        }
        if ($izbranOddelek > 0 && (int) $s["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    }

    $dodatneUre[] = $s;
}


// ==============================
// PODATKI ZA ADMINA
// ==============================

if ($user["vloga"] === "admin") {

    $predmeti = $conn->query(
        "SELECT *
         FROM Predmet
         ORDER BY naziv"
    )->fetchAll();


    $ucitelji = $conn->query(
        "SELECT *
         FROM Uporabnik
         WHERE vloga = 'ucitelj'
         ORDER BY priimek, ime"
    )->fetchAll();


    // Prihodnji dnevi brez pouka (združeni po datumu in razlogu)
    $prostiSeznam = [];

    $vrstice = $conn->query(
        "SELECT d.*, o.ime AS oddelek_ime
         FROM Dan_Brez_Pouka d
         LEFT JOIN Oddelek o ON o.id_oddelek = d.oddelek_id
         WHERE d.datum >= CURDATE()
         ORDER BY d.datum, d.razlog, o.letnik, o.ime"
    )->fetchAll();

    foreach ($vrstice as $r) {

        $kljuc = $r["datum"] . "|" . $r["razlog"];

        if (!isset($prostiSeznam[$kljuc])) {
            $prostiSeznam[$kljuc] = [
                "datum" => $r["datum"],
                "razlog" => $r["razlog"],
                "ids" => [],
                "oddelki" => [],
                "vsi" => false
            ];
        }

        $prostiSeznam[$kljuc]["ids"][] = $r["id_dan"];

        if ($r["oddelek_id"] === null) {
            $prostiSeznam[$kljuc]["vsi"] = true;
        } else {
            $prostiSeznam[$kljuc]["oddelki"][] = $r["oddelek_ime"];
        }
    }

    $oddelkiPoLetnikih = [];
    foreach ($oddelki as $o) {
        $oddelkiPoLetnikih[$o["letnik"]][] = $o;
    }
    ksort($oddelkiPoLetnikih);


    // Prihodnje enkratne spremembe urnika
    $spremembeSeznam = $conn->query(
        "SELECT s.*,
                COALESCE(s.ura, ur.ura) AS ura_st,
                pr.naziv AS predmet_naziv,
                uc.ime AS ucitelj_ime,
                uc.priimek AS ucitelj_priimek,
                od.ime AS oddelek_ime
         FROM Sprememba_Urnika s
         LEFT JOIN Urnik ur ON ur.id_urnik = s.urnik_id
         LEFT JOIN Predmet pr ON pr.id_predmet = COALESCE(s.predmet_id, ur.predmet_id)
         LEFT JOIN Uporabnik uc ON uc.id_uporabnik = COALESCE(s.ucitelj_id, ur.ucitelj_id)
         LEFT JOIN Oddelek od ON od.id_oddelek = COALESCE(s.oddelek_id, ur.oddelek_id)
         WHERE s.datum >= CURDATE()
         ORDER BY s.datum, ura_st"
    )->fetchAll();
}


// ==============================
// POLJA ZA URNIK
// ==============================

function scheduleFields(
    $entry,
    $predmeti,
    $ucitelji,
    $days
) {
    ?>

    <select name="predmet_id" required>

        <?php foreach ($predmeti as $p): ?>

            <option
                value="<?= $p["id_predmet"] ?>"
                <?= $p["id_predmet"] == $entry["predmet_id"]
                    ? "selected"
                    : "" ?>
            >
                <?= e($p["naziv"]) ?>
            </option>

        <?php endforeach; ?>

    </select>


    <select name="ucitelj_id" required>

        <?php foreach ($ucitelji as $u): ?>

            <option
                value="<?= $u["id_uporabnik"] ?>"
                <?= $u["id_uporabnik"] == $entry["ucitelj_id"]
                    ? "selected"
                    : "" ?>
            >
                <?= e(
                    $u["priimek"] . " " . $u["ime"]
                ) ?>
            </option>

        <?php endforeach; ?>

    </select>


    <select name="dan" required>

        <?php foreach ($days as $day): ?>

            <option
                value="<?= e($day) ?>"
                <?= $day === $entry["dan_v_tednu"]
                    ? "selected"
                    : "" ?>
            >
                <?= e($day) ?>
            </option>

        <?php endforeach; ?>

    </select>


    <input
        type="number"
        name="ura"
        min="0"
        max="12"
        value="<?= e($entry["ura"]) ?>"
        required
    >


    <input
        type="text"
        name="ucilnica"
        placeholder="Učilnica"
        value="<?= e($entry["ucilnica"]) ?>"
    >


    <select name="oddelek_id">

        <option value="">Brez oddelka</option>

        <?php foreach ($GLOBALS["oddelki"] as $o): ?>

            <option
                value="<?= $o["id_oddelek"] ?>"
                <?= $o["id_oddelek"] == ($entry["oddelek_id"] ?? null)
                    ? "selected"
                    : "" ?>
            >
                <?= e($o["ime"]) ?>
            </option>

        <?php endforeach; ?>

    </select>

    <?php
}


// Povezava na teden (ohrani filter oddelka)
function tedenUrl($teden, $izbranOddelek)
{
    $url = "urnik.php?teden=" . urlencode($teden);

    if ($izbranOddelek > 0) {
        $url .= "&oddelek=" . (int) $izbranOddelek;
    }

    return $url;
}


// ==============================
// STRAN
// ==============================

pageStart("Urnik");

?>

<h1>Urnik</h1>

<?php if ($user["vloga"] === "dijak" && !empty($user["oddelek_id"])): ?>

    <p>
        Oddelek:
        <strong>
            <?php foreach ($oddelki as $o): ?>
                <?= $o["id_oddelek"] == $user["oddelek_id"] ? e($o["ime"] . " (" . $o["program"] . ")") : "" ?>
            <?php endforeach; ?>
        </strong>
    </p>

<?php elseif ($user["vloga"] !== "dijak"): ?>

    <form method="get" class="panel">

        <input type="hidden" name="teden" value="<?= e($tedenVrednost) ?>">

        <label>
            Oddelek:

            <select name="oddelek" onchange="this.form.submit()">

                <option value="0">Vsi oddelki</option>

                <?php foreach ($oddelki as $o): ?>

                    <option
                        value="<?= $o["id_oddelek"] ?>"
                        <?= $o["id_oddelek"] === $izbranOddelek ? "selected" : "" ?>
                    >
                        <?= e($o["ime"] . " - " . $o["program"]) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </label>

    </form>

<?php endif; ?>


<?php if ($user["vloga"] === "admin"): ?>

    <!-- DODAJANJE URE -->

    <section class="panel">

        <h2>Dodaj uro</h2>

        <form method="post">

            <input
                type="hidden"
                name="action"
                value="dodaj"
            >


            <?php

            scheduleFields(
                [
                    "predmet_id" => 0,
                    "ucitelj_id" => 0,
                    "dan_v_tednu" => "Ponedeljek",
                    "ura" => 1,
                    "ucilnica" => "",
                    "oddelek_id" => $izbranOddelek ?: null
                ],
                $predmeti,
                $ucitelji,
                $days
            );

            ?>


            <button class="btn blue">
                Dodaj
            </button>

        </form>

    </section>


    <!-- DNEVI BREZ POUKA -->

    <section class="panel" style="margin-top: 18px;">

        <h2>Dan brez pouka</h2>

        <p class="off-hint">
            Dodaj dan, ko pouka ni (npr. športni dan, ekskurzija, prosti dan). V urniku bo ta dan označen z razlogom.
        </p>

        <form method="post" class="off-form">

            <input type="hidden" name="action" value="dodaj_prosto">

            <div class="off-row">

                <label>
                    Datum
                    <input type="date" name="datum" value="<?= date("Y-m-d") ?>" required>
                </label>

                <label class="off-grow">
                    Razlog
                    <input type="text" name="razlog" maxlength="150" placeholder="npr. Športni dan" required>
                </label>

            </div>

            <div class="off-classes">

                <span class="off-label">Za katere razrede velja?</span>

                <label class="off-all">
                    <input type="checkbox" id="prosto_vsi" name="vsi_oddelki" value="1" checked onchange="prostoVsi(this)">
                    Vsi razredi
                </label>

                <?php foreach ($oddelkiPoLetnikih as $letnik => $oddSeznam): ?>
                    <div class="off-year">
                        <span class="off-year-label"><?= (int) $letnik ?>. letnik</span>
                        <?php foreach ($oddSeznam as $o): ?>
                            <label class="off-chip">
                                <input type="checkbox" class="prosto-cb" name="oddelki[]" value="<?= $o["id_oddelek"] ?>" onchange="prostoPosamezni()">
                                <?= e($o["ime"]) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            </div>

            <button class="btn blue">Dodaj dan brez pouka</button>

        </form>


        <h3 class="off-title">Prihodnji dnevi brez pouka</h3>

        <?php if (!$prostiSeznam): ?>

            <p class="off-hint">Trenutno ni določenih dni brez pouka.</p>

        <?php else: ?>

            <?php foreach ($prostiSeznam as $p): ?>

                <?php
                $dt = new DateTime($p["datum"]);
                $dnevi = ["Nedelja", "Ponedeljek", "Torek", "Sreda", "Četrtek", "Petek", "Sobota"];
                $komu = $p["vsi"] ? "Vsi razredi" : implode(", ", $p["oddelki"]);
                ?>

                <div class="list-row">

                    <div>
                        <strong><?= e($p["razlog"]) ?></strong>
                        <br>
                        <small class="muted">
                            <?= e($dnevi[(int) $dt->format("w")] . ", " . $dt->format("j. n. Y")) ?>
                            &middot; <?= e($komu) ?>
                        </small>
                    </div>

                    <div>
                        <a class="btn small" href="urnik.php?teden=<?= e((clone $dt)->modify("monday this week")->format("Y-m-d")) ?>">Poglej</a>

                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="izbrisi_prosto">
                            <input type="hidden" name="ids" value="<?= e(implode(",", $p["ids"])) ?>">
                            <button class="btn red small" onclick="return confirm('Vrnem pouk za ta dan?')">
                                Vrni pouk
                            </button>
                        </form>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <section class="panel" style="margin-top: 18px;">
        <h3>Sprememba urnika (nulta ura, dodatna ura, nadomeščanje)</h3>
        <p class="off-hint">
            Uro lahko dodate <strong>samo za določen datum</strong> (enkratna dodatna ura, nadomeščanje ali nadomestna ura) ali <strong>trajno</strong> v tedenski urnik. Za označitev obstoječe ure kot odpadle kliknite &raquo;Odpadla ura&laquo; neposredno v urniku spodaj.
        </p>

        <form method="post" class="off-form">
            <input type="hidden" name="action" value="dodaj_spremembo">

            <div class="off-row">
                <div class="off-grow">
                    <span class="off-label">Obseg spremembe</span>
                    <div style="display: flex; gap: 20px; margin-top: 6px;">
                        <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <input type="radio" name="obseg" value="datum" checked onchange="preklopiObseg(this.value)">
                            Samo za izbran datum
                        </label>
                        <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <input type="radio" name="obseg" value="trajno" onchange="preklopiObseg(this.value)">
                            Trajno (vsak teden na ta dan v tednu)
                        </label>
                    </div>
                </div>
            </div>

            <div class="off-row" style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                <div>
                    <label class="off-label" for="sprememba_datum">Datum</label>
                    <input type="date" id="sprememba_datum" name="datum" required value="<?= e($ponedeljek->format("Y-m-d")) ?>">
                </div>

                <div>
                    <label class="off-label" for="sprememba_oddelek">Razred</label>
                    <select id="sprememba_oddelek" name="oddelek_id" required>
                        <option value="">-- Izberi razred --</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= e($o["id_oddelek"]) ?>" <?= ($izbranOddelek === (int) $o["id_oddelek"]) ? "selected" : "" ?>>
                                <?= e($o["ime"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="off-label" for="sprememba_ura">Ura</label>
                    <input type="number" id="sprememba_ura" name="ura" min="0" max="12" value="0" required style="width: 80px;" title="0 = nulta ura">
                    <small class="muted" style="display: block; font-size: 11px;">0 = nulta ura</small>
                </div>

                <div id="polje_vrsta">
                    <label class="off-label" for="sprememba_vrsta">Vrsta spremembe</label>
                    <select id="sprememba_vrsta" name="vrsta">
                        <option value="dodatna">Dodatna ura</option>
                        <option value="nadomescanje">Nadomeščanje</option>
                        <option value="nadomestna">Nadomestna ura</option>
                    </select>
                </div>
            </div>

            <div class="off-row" style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 14px;">
                <div class="off-grow" style="min-width: 200px;">
                    <label class="off-label" for="sprememba_predmet">Predmet</label>
                    <select id="sprememba_predmet" name="predmet_id" required style="width: 100%;">
                        <option value="">-- Izberi predmet --</option>
                        <?php foreach ($predmeti as $p): ?>
                            <option value="<?= e($p["id_predmet"]) ?>"><?= e($p["naziv"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="off-grow" style="min-width: 200px;">
                    <label class="off-label" for="sprememba_ucitelj">Učitelj</label>
                    <select id="sprememba_ucitelj" name="ucitelj_id" required style="width: 100%;">
                        <option value="">-- Izberi učitelja --</option>
                        <?php foreach ($ucitelji as $u): ?>
                            <option value="<?= e($u["id_uporabnik"]) ?>"><?= e($u["priimek"] . " " . $u["ime"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width: 120px;">
                    <label class="off-label" for="sprememba_ucilnica">Učilnica</label>
                    <input type="text" id="sprememba_ucilnica" name="ucilnica" placeholder="npr. 204" style="width: 100%;">
                </div>
            </div>

            <div class="off-row" id="polje_opomba" style="margin-top: 12px;">
                <div class="off-grow">
                    <label class="off-label" for="sprememba_opomba">Opomba (neobvezno, npr. razlog ali kdo koga nadomešča)</label>
                    <input type="text" id="sprememba_opomba" name="opomba" maxlength="200" placeholder="npr. Nadomeščanje odsotnega učitelja ..." style="width: 100%;">
                </div>
            </div>

            <div style="margin-top: 14px;">
                <button type="submit" class="btn blue">Dodaj v urnik</button>
            </div>
        </form>

        <h3 class="off-title" style="margin-top: 24px;">Prihodnje enkratne spremembe urnika</h3>

        <?php if (empty($spremembeSeznam)): ?>
            <p class="off-hint">Trenutno ni zabeleženih prihodnjih sprememb urnika.</p>
        <?php else: ?>
            <?php
            $dneviImena = ["Nedelja", "Ponedeljek", "Torek", "Sreda", "Četrtek", "Petek", "Sobota"];
            ?>
            <?php foreach ($spremembeSeznam as $s): ?>
                <?php
                $sDatum = new DateTime($s["datum"]);
                $sDanIdx = (int) $sDatum->format("w");
                $vrstaTekst = [
                    "dodatna" => "Dodatna ura",
                    "nadomescanje" => "Nadomeščanje",
                    "nadomestna" => "Nadomestna ura"
                ][$s["vrsta"] ?? "dodatna"] ?? "Dodatna ura";
                ?>
                <div class="list-row">
                    <div>
                        <?php if ($s["tip"] === "odpade"): ?>
                            <span class="schedule-cancel-label">Odpadla ura</span>
                            <strong><?= e($s["ura_st"] . ". ura – " . $s["predmet_naziv"]) ?></strong>
                            (<?= e($s["oddelek_ime"]) ?>)
                            <?php if (!empty($s["opomba"])): ?>
                                <span class="muted">&middot; Razlog: <?= e($s["opomba"]) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="schedule-extra-badge"><?= e($vrstaTekst) ?></span>
                            <strong><?= e($s["ura_st"] . ". ura – " . $s["predmet_naziv"]) ?></strong>
                            (<?= e($s["oddelek_ime"]) ?>)
                            <span class="muted">&middot; <?= e($s["ucitelj_priimek"] . " " . $s["ucitelj_ime"]) ?></span>
                            <?php if (!empty($s["ucilnica"])): ?>
                                <span class="muted">(<?= e($s["ucilnica"]) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($s["opomba"])): ?>
                                <div class="schedule-extra-note"><?= e($s["opomba"]) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <br>
                        <small class="muted">
                            <?= e($dneviImena[$sDanIdx] . ", " . $sDatum->format("j. n. Y")) ?>
                        </small>
                    </div>

                    <div>
                        <a class="btn small" href="urnik.php?teden=<?= e((clone $sDatum)->modify("monday this week")->format("Y-m-d")) ?>&oddelek_id=<?= e($s["oddelek_id"]) ?>">Poglej</a>

                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="izbrisi_spremembo">
                            <input type="hidden" name="id" value="<?= e($s["id_sprememba"]) ?>">
                            <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                            <button class="btn red small" onclick="return confirm('<?= $s["tip"] === "odpade" ? "Vrnem to uro v urnik?" : "Odstranim to dodatno uro?" ?>')">
                                <?= $s["tip"] === "odpade" ? "Vrni uro" : "Odstrani" ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </section>

    <script>
    function preklopiObseg(val) {
        var poljeVrsta = document.getElementById('polje_vrsta');
        var poljeOpomba = document.getElementById('polje_opomba');
        if (val === 'trajno') {
            if (poljeVrsta) poljeVrsta.style.display = 'none';
            if (poljeOpomba) poljeOpomba.style.display = 'none';
        } else {
            if (poljeVrsta) poljeVrsta.style.display = '';
            if (poljeOpomba) poljeOpomba.style.display = '';
        }
    }
    function prostoVsi(cb) {
        if (cb.checked) {
            document.querySelectorAll('.prosto-cb').forEach(function (x) { x.checked = false; });
        }
    }
    function prostoPosamezni() {
        var vsi = document.getElementById('prosto_vsi');
        var nobenega = document.querySelectorAll('.prosto-cb:checked').length === 0;
        vsi.checked = nobenega;
    }
    </script>

<?php endif; ?>


<!-- URNIK -->

<?php
$byCell = [];
$maxUra = 0;
$minUra = 99;

foreach ($entries as $entry) {
    $byCell[(int)$entry["ura"]][$entry["dan_v_tednu"]][] = $entry;
    $maxUra = max($maxUra, (int)$entry["ura"]);
    $minUra = min($minUra, (int)$entry["ura"]);
}

// Dodatne / nadomestne ure za izbrani teden
foreach ($dodatneUre as $s) {

    $dnevIdx = (int) (new DateTime($s["datum"]))->format("N") - 1;

    if (!isset($days[$dnevIdx])) {
        continue;
    }

    $uraSt = (int) $s["ura"];

    $byCell[$uraSt][$days[$dnevIdx]][] = [
        "extra" => true,
        "id_sprememba" => $s["id_sprememba"],
        "vrsta" => $s["vrsta"],
        "opomba" => $s["opomba"],
        "naziv" => $s["predmet_naziv"],
        "ime" => $s["ucitelj_ime"],
        "priimek" => $s["ucitelj_priimek"],
        "ucilnica" => $s["ucilnica"],
        "oddelek" => $s["oddelek_ime"],
        "oddelek_id" => $s["oddelek_id"]
    ];

    $maxUra = max($maxUra, $uraSt);
    $minUra = min($minUra, $uraSt);
}

$imaVnose = (bool) $entries || (bool) $dodatneUre;

if (!$imaVnose) {
    $minUra = 1;
}

$jeDijak = $user["vloga"] === "dijak";
$mesecDo = $datumiTedna["Petek"];
$vrsteNazivi = [
    "dodatna" => "Dodatna ura",
    "nadomescanje" => "Nadomeščanje",
    "nadomestna" => "Nadomestna ura"
];
?>

<div class="week-nav">

    <a class="btn small" href="<?= e(tedenUrl($tedenPrej, $izbranOddelek)) ?>">&larr; Prejšnji teden</a>

    <div class="week-title">
        <?= e($ponedeljek->format("j. n.") . " – " . $mesecDo->format("j. n. Y")) ?>
        <?php if ($tedenVrednost !== $tedenTa): ?>
            <a class="week-today" href="<?= e(tedenUrl($tedenTa, $izbranOddelek)) ?>">Ta teden</a>
        <?php endif; ?>
    </div>

    <a class="btn small" href="<?= e(tedenUrl($tedenNaprej, $izbranOddelek)) ?>">Naslednji teden &rarr;</a>

</div>

<div class="schedule-table-wrap">
<table class="schedule-table">

    <thead>
        <tr>
            <th>Ura</th>
            <?php foreach ($days as $day): ?>
                <?php
                $datumKljuc = $datumiTedna[$day]->format("Y-m-d");
                $opombe = opombeDneva($prostiTedna[$datumKljuc] ?? [], $kontekstOddelek, $jeDijak);
                ?>
                <th class="<?= $opombe ? "schedule-day-off" : "" ?>">
                    <?= e($day) ?>
                    <br>
                    <small class="schedule-date"><?= e($datumiTedna[$day]->format("j. n.")) ?></small>

                    <?php foreach ($opombe as $razlog => $razredi): ?>
                        <span class="schedule-off-note">
                            <?= e($razlog) ?><?= $razredi ? " (" . e(implode(", ", $razredi)) . ")" : "" ?>
                        </span>
                    <?php endforeach; ?>
                </th>
            <?php endforeach; ?>
        </tr>
    </thead>

    <tbody>

    <?php if (!$imaVnose): ?>

        <tr>
            <td colspan="<?= count($days) + 1 ?>">Ni vnešenih ur.</td>
        </tr>

    <?php else: ?>

        <?php for ($ura = $minUra; $ura <= $maxUra; $ura++): ?>

            <tr>
                <th><?= $ura ?>. ura</th>

                <?php foreach ($days as $day): ?>

                    <?php $datumKljuc = $datumiTedna[$day]->format("Y-m-d"); ?>

                    <td>

                    <?php foreach ($byCell[$ura][$day] ?? [] as $entry): ?>

                        <?php
                        $jeExtra = !empty($entry["extra"]);
                        $dnevniRazlog = null;
                        $enkratna = null;

                        if (!$jeExtra) {
                            $dnevniRazlog = prostDanRazlog(
                                $prostiTedna[$datumKljuc] ?? [],
                                $entry["oddelek_id"] !== null ? (int) $entry["oddelek_id"] : null
                            );
                            $enkratna = $odpadleUre[$datumKljuc][(int) $entry["id_urnik"]] ?? null;
                        }

                        $odpade = $dnevniRazlog !== null || $enkratna !== null;

                        $cellClass = "schedule-cell";
                        if ($odpade) {
                            $cellClass .= " schedule-cancelled";
                        }
                        if ($jeExtra) {
                            $cellClass .= " schedule-extra";
                        }
                        ?>

                        <div class="<?= $cellClass ?>">

                            <?php if ($jeExtra): ?>
                                <span class="schedule-extra-badge">
                                    <?= e($vrsteNazivi[$entry["vrsta"]] ?? "Dodatna ura") ?>
                                </span>
                            <?php endif; ?>

                            <strong><?= e($entry["naziv"]) ?></strong>

                            <br>

                            <small>
                                <?= e($entry["priimek"] . " " . $entry["ime"]) ?>
                                ·
                                <?= e($entry["ucilnica"]) ?>
                            </small>

                            <?php if ($user["vloga"] !== "dijak" && $entry["oddelek"]): ?>
                                <br>
                                <small class="muted">
                                    <?= e($entry["oddelek"]) ?>
                                </small>
                            <?php endif; ?>

                            <?php if ($jeExtra && !empty($entry["opomba"])): ?>
                                <br>
                                <small class="schedule-extra-note"><?= e($entry["opomba"]) ?></small>
                            <?php endif; ?>

                            <?php if ($dnevniRazlog !== null): ?>
                                <br>
                                <small class="schedule-cancel-label">Ni pouka: <?= e($dnevniRazlog) ?></small>
                            <?php elseif ($enkratna !== null): ?>
                                <br>
                                <small class="schedule-cancel-label">
                                    Odpadla ura<?= !empty($enkratna["razlog"]) ? ": " . e($enkratna["razlog"]) : "" ?>
                                </small>
                            <?php endif; ?>

                            <?php if ($user["vloga"] === "admin" && $jeExtra): ?>

                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="izbrisi_spremembo">
                                    <input type="hidden" name="id" value="<?= (int) $entry["id_sprememba"] ?>">
                                    <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                    <button class="btn red small" onclick="return confirm('Odstranim to uro?')">Odstrani</button>
                                </form>

                            <?php elseif ($user["vloga"] === "admin"): ?>

                                <details>

                                    <summary>Uredi</summary>

                                    <?php if ($enkratna !== null): ?>

                                        <form method="post">
                                            <input type="hidden" name="action" value="izbrisi_spremembo">
                                            <input type="hidden" name="id" value="<?= (int) $enkratna["id"] ?>">
                                            <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                            <button class="btn small">Vrni uro (<?= e($datumiTedna[$day]->format("j. n.")) ?>)</button>
                                        </form>

                                    <?php else: ?>

                                        <form method="post">
                                            <input type="hidden" name="action" value="odpade_ura">
                                            <input type="hidden" name="urnik_id" value="<?= (int) $entry["id_urnik"] ?>">
                                            <input type="hidden" name="datum" value="<?= e($datumKljuc) ?>">
                                            <input type="text" name="razlog" maxlength="200" placeholder="Razlog (npr. odsotnost učitelja)">
                                            <button class="btn small">Odpadla ura samo <?= e($datumiTedna[$day]->format("j. n.")) ?></button>
                                        </form>

                                    <?php endif; ?>

                                    <form method="post">

                                        <input type="hidden" name="action" value="uredi">
                                        <input type="hidden" name="id" value="<?= $entry["id_urnik"] ?>">

                                        <?php
                                        scheduleFields(
                                            $entry,
                                            $predmeti,
                                            $ucitelji,
                                            $days
                                        );
                                        ?>

                                        <button class="btn small">Shrani (trajno)</button>

                                    </form>

                                    <form method="post">

                                        <input type="hidden" name="action" value="izbrisi">
                                        <input type="hidden" name="id" value="<?= $entry["id_urnik"] ?>">

                                        <button
                                            class="btn red small"
                                            onclick="return confirm('Trajno izbrišem uro iz tedenskega urnika?')"
                                        >
                                            Izbriši trajno
                                        </button>

                                    </form>

                                </details>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                    </td>

                <?php endforeach; ?>
            </tr>

        <?php endfor; ?>

    <?php endif; ?>

    </tbody>
</table>
</div>


<?php pageEnd(); ?>
