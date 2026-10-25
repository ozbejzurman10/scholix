<?php

require_once "funkcije.php";

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
// AKCIJE (ADMIN IN UČITELJ)
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if (in_array($action, [
        "dodaj_ocenjevanje",
        "izbrisi_ocenjevanje",
        "uredi",
        "dodaj",
        "izbrisi",
        "odpade_ura",
        "dodaj_spremembo",
        "izbrisi_spremembo"
    ], true)) {
        if (!in_array($user["vloga"] ?? "", ["ucitelj", "admin"], true)) {
            redirect("nadzorna_plosca.php");
        }
    } else {
        requireRole("admin");
    }

    $redirectUrl = "urnik.php";

    try {

        // DODAJ PISNO OCENJEVANJE
        if ($action === "dodaj_ocenjevanje") {

            $datum = trim($_POST["datum"] ?? "");
            $datumObj = DateTime::createFromFormat("Y-m-d", $datum);

            if (!$datumObj || $datumObj->format("Y-m-d") !== $datum) {
                throw new RuntimeException("Vnesite veljaven datum pisnega ocenjevanja.");
            }

            $dnevIndex = (int) $datumObj->format("N") - 1;
            if (!isset($days[$dnevIndex])) {
                throw new RuntimeException("Pisno ocenjevanje je mogoče določiti le na delovni dan (ponedeljek–petek).");
            }

            $oddelekId = (int) ($_POST["oddelek_id"] ?? 0);
            $predmetId = (int) ($_POST["predmet_id"] ?? 0);
            $uraSt = isset($_POST["ura"]) && $_POST["ura"] !== "" ? (int) $_POST["ura"] : -1;
            $naslov = trim($_POST["naslov"] ?? "");
            $opis = trim($_POST["opis"] ?? "");

            if ($oddelekId <= 0 || $predmetId <= 0) {
                throw new RuntimeException("Izberite predmet in razred.");
            }

            $stmtCheckRu = $conn->prepare("SELECT naziv FROM Predmet WHERE id_predmet = ?");
            $stmtCheckRu->execute([$predmetId]);
            $predmetNazivPreveri = (string) ($stmtCheckRu->fetchColumn() ?: "");
            if (mb_strtolower(trim($predmetNazivPreveri), "UTF-8") === "razredna ura") {
                throw new RuntimeException("Za razredno uro ni mogoče razpisati pisnega ocenjevanja.");
            }

            if ($uraSt < 0 || $uraSt > 12) {
                throw new RuntimeException("Izberite veljavno šolsko uro (med 0 in 12).");
            }

            if ($naslov === "" || mb_strlen($naslov) > 150) {
                throw new RuntimeException("Vnesite naslov pisnega ocenjevanja (največ 150 znakov).");
            }

            if (mb_strlen($opis) > 255) {
                throw new RuntimeException("Opis snovi je predolg (največ 255 znakov).");
            }

            if ($user["vloga"] === "ucitelj") {
                $uciteljId = (int) $user["id_uporabnik"];

                $stmtCheck = $conn->prepare("
                    SELECT 1 FROM Ucitelj_Predmet WHERE ucitelj_id = ? AND predmet_id = ?
                    UNION
                    SELECT 1 FROM Urnik WHERE ucitelj_id = ? AND predmet_id = ?
                ");
                $stmtCheck->execute([$uciteljId, $predmetId, $uciteljId, $predmetId]);
                if (!$stmtCheck->fetchColumn()) {
                    throw new RuntimeException("Pisna ocenjevanja lahko dodeljujete le za svoje predmete.");
                }
            } else {
                $uciteljId = (int) ($_POST["ucitelj_id"] ?? 0);
                if ($uciteljId <= 0) {
                    $stmtFind = $conn->prepare("
                        SELECT ucitelj_id FROM Urnik WHERE predmet_id = ? AND oddelek_id = ? LIMIT 1
                    ");
                    $stmtFind->execute([$predmetId, $oddelekId]);
                    $uciteljId = (int) ($stmtFind->fetchColumn() ?: 0);
                }
                if ($uciteljId <= 0) {
                    $stmtFind2 = $conn->prepare("
                        SELECT ucitelj_id FROM Ucitelj_Predmet WHERE predmet_id = ? LIMIT 1
                    ");
                    $stmtFind2->execute([$predmetId]);
                    $uciteljId = (int) ($stmtFind2->fetchColumn() ?: $user["id_uporabnik"]);
                }
            }

            // Če za isti datum, uro, oddelek in predmet že obstaja ocenjevanje, ga posodobimo
            $conn->prepare("
                DELETE FROM Pisno_Ocenjevanje
                WHERE datum = ? AND ura = ? AND oddelek_id = ? AND predmet_id = ?
            ")->execute([$datum, $uraSt, $oddelekId, $predmetId]);

            $conn->prepare("
                INSERT INTO Pisno_Ocenjevanje (datum, ura, predmet_id, ucitelj_id, oddelek_id, naslov, opis)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $datum,
                $uraSt,
                $predmetId,
                $uciteljId,
                $oddelekId,
                $naslov,
                $opis !== "" ? $opis : null
            ]);

            $ponedeljekCilj = (clone $datumObj)->modify("monday this week")->format("Y-m-d");
            $redirectUrl = "urnik.php?teden=" . $ponedeljekCilj;
            if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
            }

            flash("Pisno ocenjevanje je bilo dodano na urnik.");
        }

        // ODSTRANI PISNO OCENJEVANJE
        elseif ($action === "izbrisi_ocenjevanje") {

            $id = (int) ($_POST["id"] ?? 0);

            if ($user["vloga"] === "ucitelj") {
                $conn->prepare("
                    DELETE FROM Pisno_Ocenjevanje WHERE id_ocenjevanje = ? AND ucitelj_id = ?
                ")->execute([$id, $user["id_uporabnik"]]);
            } else {
                $conn->prepare("
                    DELETE FROM Pisno_Ocenjevanje WHERE id_ocenjevanje = ?
                ")->execute([$id]);
            }

            $tedenNazaj = (!empty($_POST["nazaj_teden"]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST["nazaj_teden"]))
                ? $_POST["nazaj_teden"]
                : $tedenVrednost;

            $redirectUrl = "urnik.php?teden=" . $tedenNazaj;
            if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
            }

            flash("Pisno ocenjevanje je bilo odstranjeno z urnika.");
        }

        // IZBRIŠI URO
        elseif ($action === "izbrisi") {

            if ($user["vloga"] === "ucitelj") {
                $stmt = $conn->prepare("DELETE FROM Urnik WHERE id_urnik = ? AND ucitelj_id = ?");
                $stmt->execute([(int) $_POST["id"], (int) $user["id_uporabnik"]]);
            } else {
                $stmt = $conn->prepare("DELETE FROM Urnik WHERE id_urnik = ?");
                $stmt->execute([(int) $_POST["id"]]);
            }

            if (!empty($_POST["nazaj_teden"]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST["nazaj_teden"])) {
                $redirectUrl = "urnik.php?teden=" . $_POST["nazaj_teden"];
                if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                    $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
                }
            }

            flash("Urnik je bil posodobljen.");
        }

        // DODAJ ALI UREDI URO
        elseif (in_array($action, ["dodaj", "uredi"], true)) {

            $uciteljIdCilj = ($user["vloga"] === "ucitelj")
                ? (int) $user["id_uporabnik"]
                : (int) $_POST["ucitelj_id"];

            $vrednosti = [
                (int) $_POST["predmet_id"],
                $uciteljIdCilj,
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

                if ($user["vloga"] === "ucitelj") {
                    $vrednosti[] = (int) $user["id_uporabnik"];
                    $stmt = $conn->prepare(
                        "UPDATE Urnik
                         SET predmet_id = ?,
                             ucitelj_id = ?,
                             dan_v_tednu = ?,
                             ura = ?,
                             ucilnica = ?,
                             oddelek_id = ?
                         WHERE id_urnik = ? AND ucitelj_id = ?"
                    );
                } else {
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
                }

                $stmt->execute($vrednosti);
            }

            if (!empty($_POST["nazaj_teden"]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST["nazaj_teden"])) {
                $redirectUrl = "urnik.php?teden=" . $_POST["nazaj_teden"];
                if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                    $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
                }
            }

            flash("Ura in učilnica v urniku sta bili posodobljeni.");

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

            if ($user["vloga"] === "ucitelj" && (int) $ura["ucitelj_id"] !== (int) $user["id_uporabnik"]) {
                throw new RuntimeException("Spreminjate lahko le svoje ure.");
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
            if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
            }

            flash("Ura je za izbran datum označena kot odpadla.");
        }

        // DODATNA / NADOMESTNA URA, ODPOVED URE ALI CELODNEVNI DOGODEK (enkratno ali trajno, posamezen razred ali vsi oddelki)
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

            $oddelekRaw = trim((string) ($_POST["oddelek_id"] ?? ""));
            $jeVsiOddelki = ($oddelekRaw === "vsi");
            $oddelekId = $jeVsiOddelki ? 0 : (int) $oddelekRaw;
            $vrsta = $_POST["vrsta"] ?? "dodatna";
            $uraRaw = trim((string) ($_POST["ura"] ?? "1"));
            $jeCelDan = ($obseg === "cel_dan" || $uraRaw === "cel_dan" || ($obseg !== "trajno" && $vrsta === "dogodek_prosto"));
            $opomba = trim($_POST["opomba"] ?? "");

            if (mb_strlen($opomba) > 200) {
                throw new RuntimeException("Opomba je predolga (največ 200 znakov).");
            }

            if (!$jeVsiOddelki && $oddelekId <= 0) {
                throw new RuntimeException("Izberite razred ali opcijo »Vsi oddelki (cela šola)«.");
            }

            // 1. CELODNEVNI DOGODEK / ODPADE POUK ZA CEL DAN (npr. športni dan, kulturni dan, prosti dan)
            if ($jeCelDan) {
                $razlog = $opomba !== "" ? mb_substr($opomba, 0, 150) : "Odpade pouk / šolski dogodek";
                $stmtIns = $conn->prepare(
                    "INSERT INTO Dan_Brez_Pouka (datum, razlog, oddelek_id) VALUES (?, ?, ?)"
                );
                if ($jeVsiOddelki) {
                    $stmtIns->execute([$datum, $razlog, null]);
                    flash("Celodnevni dogodek / odpoved pouka (»" . $razlog . "«) je bil dodan za vse oddelke (celo šolo).");
                } else {
                    $stmtIns->execute([$datum, $razlog, $oddelekId]);
                    flash("Celodnevni dogodek / odpoved pouka (»" . $razlog . "«) je bil dodan za izbrani oddelek.");
                }
            }
            // 2. ODPADE POSAMEZNA ŠOLSKA URA NA IZBRAN DATUM
            elseif ($obseg !== "trajno" && $vrsta === "odpade_ura_slot") {
                $uraSt = (int) $uraRaw;
                if ($uraSt < 0 || $uraSt > 12) {
                    throw new RuntimeException("Izberite veljavno šolsko uro (0–12) ali Cel dan.");
                }

                $sqlFind = "SELECT id_urnik FROM Urnik WHERE dan_v_tednu = ? AND ura = ?";
                $paramsFind = [$days[$dnevIndex], $uraSt];
                if (!$jeVsiOddelki) {
                    $sqlFind .= " AND oddelek_id = ?";
                    $paramsFind[] = $oddelekId;
                }
                if ($user["vloga"] === "ucitelj") {
                    $sqlFind .= " AND ucitelj_id = ?";
                    $paramsFind[] = (int) $user["id_uporabnik"];
                }

                $stmtFind = $conn->prepare($sqlFind);
                $stmtFind->execute($paramsFind);
                $najdeneUreIds = array_map("intval", $stmtFind->fetchAll(PDO::FETCH_COLUMN));

                if (empty($najdeneUreIds)) {
                    throw new RuntimeException("V urniku na ta dan pri " . $uraSt . ". uri ni redne ure za izbrani razred.");
                }

                $conn->beginTransaction();
                $stmtDel = $conn->prepare("DELETE FROM Sprememba_Urnika WHERE tip = 'odpade' AND urnik_id = ? AND datum = ?");
                $stmtInsOdp = $conn->prepare("INSERT INTO Sprememba_Urnika (datum, tip, urnik_id, opomba) VALUES (?, 'odpade', ?, ?)");
                foreach ($najdeneUreIds as $uId) {
                    $stmtDel->execute([$uId, $datum]);
                    $stmtInsOdp->execute([$datum, $uId, $opomba !== "" ? $opomba : null]);
                }
                $conn->commit();

                flash("Izbrana " . $uraSt . ". ura je označena kot odpadla.");
            }
            // 3. DODATNA URA / NADOMEŠČANJE / NADOMESTNA URA ALI TRAJNI VNOS V URNIK
            else {
                $predmetId = (int) ($_POST["predmet_id"] ?? 0);
                $uciteljId = ($user["vloga"] === "ucitelj")
                    ? (int) $user["id_uporabnik"]
                    : (int) ($_POST["ucitelj_id"] ?? 0);
                $uraSt = (int) $uraRaw;
                $ucilnica = trim($_POST["ucilnica"] ?? "");

                if ($predmetId <= 0 || $uciteljId <= 0) {
                    throw new RuntimeException("Izberite predmet in učitelja.");
                }

                if ($uraSt < 0 || $uraSt > 12) {
                    throw new RuntimeException("Ura mora biti med 0 in 12.");
                }

                $ciljniOddelkiIds = [];
                if ($jeVsiOddelki) {
                    $ciljniOddelkiIds = array_map(
                        "intval",
                        $conn->query("SELECT id_oddelek FROM Oddelek ORDER BY id_oddelek")->fetchAll(PDO::FETCH_COLUMN)
                    );
                } else {
                    $ciljniOddelkiIds = [$oddelekId];
                }

                if ($obseg === "trajno") {
                    $conn->beginTransaction();
                    $stmtTrajno = $conn->prepare(
                        "INSERT INTO Urnik (predmet_id, ucitelj_id, dan_v_tednu, ura, ucilnica, oddelek_id)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($ciljniOddelkiIds as $cOddId) {
                        $stmtTrajno->execute([$predmetId, $uciteljId, $days[$dnevIndex], $uraSt, $ucilnica, $cOddId]);
                    }
                    $conn->commit();

                    flash($jeVsiOddelki
                        ? "Ura je bila trajno dodana v tedenski urnik za vse oddelke (" . $days[$dnevIndex] . ", " . $uraSt . ". ura)."
                        : "Ura je bila trajno dodana v tedenski urnik (" . $days[$dnevIndex] . ", " . $uraSt . ". ura)."
                    );
                } else {
                    if (!in_array($vrsta, ["dodatna", "nadomescanje", "nadomestna"], true)) {
                        $vrsta = "dodatna";
                    }

                    $conn->beginTransaction();
                    $stmtSpr = $conn->prepare(
                        "INSERT INTO Sprememba_Urnika
                         (datum, tip, vrsta, oddelek_id, ura, predmet_id, ucitelj_id, ucilnica, opomba)
                         VALUES (?, 'dodatna', ?, ?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($ciljniOddelkiIds as $cOddId) {
                        $stmtSpr->execute([
                            $datum, $vrsta, $cOddId, $uraSt, $predmetId, $uciteljId,
                            $ucilnica !== "" ? $ucilnica : null,
                            $opomba !== "" ? $opomba : null
                        ]);
                    }
                    $conn->commit();

                    flash($jeVsiOddelki
                        ? "Sprememba urnika je bila dodana za vse oddelke."
                        : "Sprememba urnika je bila dodana za izbran datum."
                    );
                }
            }

            $redirectUrl = "urnik.php?teden=" . (clone $datumObj)->modify("monday this week")->format("Y-m-d");
            if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
            }
        }

        // ODSTRANI ENKRATNO SPREMEMBO (vrni uro / odstrani dodatno uro)
        elseif ($action === "izbrisi_spremembo") {

            $id = (int) ($_POST["id"] ?? 0);

            if ($user["vloga"] === "ucitelj") {
                $stmtPreveri = $conn->prepare("
                    SELECT s.id_sprememba
                    FROM Sprememba_Urnika s
                    LEFT JOIN Urnik ur ON ur.id_urnik = s.urnik_id
                    WHERE s.id_sprememba = ? AND COALESCE(s.ucitelj_id, ur.ucitelj_id) = ?
                ");
                $stmtPreveri->execute([$id, (int) $user["id_uporabnik"]]);
                if ($stmtPreveri->fetchColumn()) {
                    $conn->prepare("DELETE FROM Sprememba_Urnika WHERE id_sprememba = ?")->execute([$id]);
                }
            } else {
                $conn->prepare("DELETE FROM Sprememba_Urnika WHERE id_sprememba = ?")->execute([$id]);
            }

            if (!empty($_POST["nazaj_teden"]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST["nazaj_teden"])) {
                $redirectUrl = "urnik.php?teden=" . $_POST["nazaj_teden"];
                if (!empty($_POST["nazaj_oddelek"]) && (int) $_POST["nazaj_oddelek"] > 0) {
                    $redirectUrl .= "&oddelek=" . (int) $_POST["nazaj_oddelek"];
                }
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
        u.kabinet,
        u.telefon,
        u.govorilne_ure,
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

// Vsi učitelji (za adminov filter po učiteljih)
$vsiUcitelji = [];
if ($user["vloga"] === "admin") {
    $vsiUcitelji = $conn->query(
        "SELECT id_uporabnik, ime, priimek, kabinet, telefon, govorilne_ure
         FROM Uporabnik
         WHERE vloga = 'ucitelj'
         ORDER BY priimek, ime"
    )->fetchAll();
}

$izbranOddelek = isset($_GET["oddelek"]) ? (int) $_GET["oddelek"] : 0;
$izbranUcitelj = ($user["vloga"] === "admin") ? (int) ($_GET["ucitelj"] ?? 0) : 0;

$izbranUciteljPodatki = null;
if ($izbranUcitelj > 0) {
    foreach ($vsiUcitelji as $uItem) {
        if ((int)$uItem["id_uporabnik"] === $izbranUcitelj) {
            $izbranUciteljPodatki = $uItem;
            break;
        }
    }
    if (!$izbranUciteljPodatki) {
        $izbranUcitelj = 0;
    }
}

// Administrator privzeto izbere prvi oddelek LE, če ni izbral nobenega učitelja in nobenega oddelka
if ($user["vloga"] === "admin" && $izbranOddelek <= 0 && $izbranUcitelj <= 0 && !empty($oddelki)) {
    $izbranOddelek = (int) $oddelki[0]["id_oddelek"];
}


// UČITELJ (svoj urnik)
if ($user["vloga"] === "ucitelj") {

    $where[] = "ur.ucitelj_id = ?";
    $params[] = $user["id_uporabnik"];
}


// ADMIN (pregled izbranega učitelja)
elseif ($user["vloga"] === "admin" && $izbranUcitelj > 0) {

    $where[] = "ur.ucitelj_id = ?";
    $params[] = $izbranUcitelj;
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
        if ($user["vloga"] === "admin" && $izbranUcitelj > 0 && (int) $s["ucitelj_id"] !== $izbranUcitelj) {
            continue;
        }
        if ($izbranOddelek > 0 && (int) $s["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    }

    $dodatneUre[] = $s;
}


// ==============================
// PISNA OCENJEVANJA V IZBRANEM TEDNU IN PRIHODNJA OCENJEVANJA
// ==============================

$dijakPredmetiIds = [];
if ($user["vloga"] === "dijak" && empty($user["oddelek_id"])) {
    $stmtDp = $conn->prepare("SELECT predmet_id FROM Dijak_Predmet WHERE dijak_id = ?");
    $stmtDp->execute([$user["id_uporabnik"]]);
    $dijakPredmetiIds = array_map("intval", $stmtDp->fetchAll(PDO::FETCH_COLUMN));
}

$ocenjevanjaTedna = [];
$ocenjevanjaDneva = [];

$stmtOcTeden = $conn->prepare(
    "SELECT po.*,
            pr.naziv AS predmet_naziv,
            uc.ime AS ucitelj_ime,
            uc.priimek AS ucitelj_priimek,
            od.ime AS oddelek_ime
     FROM Pisno_Ocenjevanje po
     JOIN Predmet pr ON pr.id_predmet = po.predmet_id
     JOIN Uporabnik uc ON uc.id_uporabnik = po.ucitelj_id
     JOIN Oddelek od ON od.id_oddelek = po.oddelek_id
     WHERE po.datum BETWEEN ? AND ?
     ORDER BY po.datum, po.ura"
);
$stmtOcTeden->execute([
    $ponedeljek->format("Y-m-d"),
    $datumiTedna["Petek"]->format("Y-m-d")
]);

foreach ($stmtOcTeden->fetchAll() as $oc) {
    if ($user["vloga"] === "dijak") {
        if (!empty($user["oddelek_id"])) {
            if ((int) $oc["oddelek_id"] !== (int) $user["oddelek_id"]) {
                continue;
            }
        } else {
            if (!in_array((int) $oc["predmet_id"], $dijakPredmetiIds, true)) {
                continue;
            }
        }
    } elseif ($user["vloga"] === "ucitelj") {
        if ($izbranOddelek > 0) {
            if ((int) $oc["oddelek_id"] !== $izbranOddelek) {
                continue;
            }
        } else {
            if ((int) $oc["ucitelj_id"] !== (int) $user["id_uporabnik"]) {
                continue;
            }
        }
    } elseif ($user["vloga"] === "admin" && $izbranUcitelj > 0) {
        if ($izbranOddelek > 0) {
            if ((int) $oc["oddelek_id"] !== $izbranOddelek) {
                continue;
            }
        } else {
            if ((int) $oc["ucitelj_id"] !== $izbranUcitelj) {
                continue;
            }
        }
    } else {
        if ($izbranOddelek > 0 && (int) $oc["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    }

    $ocenjevanjaTedna[] = $oc;
    $ocenjevanjaDneva[$oc["datum"]][] = $oc;
}

// Prihodnja pisna ocenjevanja (od danes naprej)
$prihodnjaOcenjevanja = [];
$stmtOcPrih = $conn->query(
    "SELECT po.*,
            pr.naziv AS predmet_naziv,
            uc.ime AS ucitelj_ime,
            uc.priimek AS ucitelj_priimek,
            od.ime AS oddelek_ime
     FROM Pisno_Ocenjevanje po
     JOIN Predmet pr ON pr.id_predmet = po.predmet_id
     JOIN Uporabnik uc ON uc.id_uporabnik = po.ucitelj_id
     JOIN Oddelek od ON od.id_oddelek = po.oddelek_id
     WHERE po.datum >= CURDATE()
     ORDER BY po.datum, po.ura"
);

foreach ($stmtOcPrih->fetchAll() as $oc) {
    if ($user["vloga"] === "dijak") {
        if (!empty($user["oddelek_id"])) {
            if ((int) $oc["oddelek_id"] !== (int) $user["oddelek_id"]) {
                continue;
            }
        } else {
            if (!in_array((int) $oc["predmet_id"], $dijakPredmetiIds, true)) {
                continue;
            }
        }
    } elseif ($user["vloga"] === "ucitelj") {
        if ((int) $oc["ucitelj_id"] !== (int) $user["id_uporabnik"]) {
            continue;
        }
        if ($izbranOddelek > 0 && (int) $oc["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    } elseif ($user["vloga"] === "admin" && $izbranUcitelj > 0) {
        if ((int) $oc["ucitelj_id"] !== $izbranUcitelj) {
            continue;
        }
        if ($izbranOddelek > 0 && (int) $oc["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    } else {
        if ($izbranOddelek > 0 && (int) $oc["oddelek_id"] !== $izbranOddelek) {
            continue;
        }
    }

    $prihodnjaOcenjevanja[] = $oc;
}

// Podatki za obrazec pisnega ocenjevanja (učitelj in admin)
$uciteljPredmeti = [];
$uciteljPredmetOddelkiMap = [];
$uciteljUrnikSlotMap = [];

if ($user["vloga"] === "ucitelj") {
    $stmtUp = $conn->prepare("
        SELECT DISTINCT p.id_predmet, p.naziv
        FROM Predmet p
        WHERE p.id_predmet IN (
            SELECT predmet_id FROM Ucitelj_Predmet WHERE ucitelj_id = ?
            UNION
            SELECT predmet_id FROM Urnik WHERE ucitelj_id = ?
        )
        ORDER BY p.naziv
    ");
    $stmtUp->execute([$user["id_uporabnik"], $user["id_uporabnik"]]);
    $uciteljPredmeti = $stmtUp->fetchAll();

    $stmtUrMap = $conn->prepare("
        SELECT ur.predmet_id, ur.oddelek_id, ur.dan_v_tednu, ur.ura, ur.ucilnica,
               o.ime AS oddelek_ime, o.letnik
        FROM Urnik ur
        JOIN Oddelek o ON o.id_oddelek = ur.oddelek_id
        WHERE ur.ucitelj_id = ? AND ur.oddelek_id IS NOT NULL
        ORDER BY o.letnik, o.ime, ur.ura
    ");
    $stmtUrMap->execute([$user["id_uporabnik"]]);
    foreach ($stmtUrMap->fetchAll() as $r) {
        $pid = (int) $r["predmet_id"];
        $oid = (int) $r["oddelek_id"];
        if (!isset($uciteljPredmetOddelkiMap[$pid][$oid])) {
            $uciteljPredmetOddelkiMap[$pid][$oid] = [
                "id" => $oid,
                "ime" => $r["oddelek_ime"],
                "letnik" => (int) $r["letnik"]
            ];
        }
        $slotKey = $pid . "_" . $oid . "_" . $r["dan_v_tednu"];
        $uciteljUrnikSlotMap[$slotKey][] = [
            "ura" => (int) $r["ura"],
            "ucilnica" => $r["ucilnica"]
        ];
    }
    foreach ($uciteljPredmetOddelkiMap as $pid => $map) {
        $uciteljPredmetOddelkiMap[$pid] = array_values($map);
    }
} elseif ($user["vloga"] === "admin") {
    $stmtUrMapAll = $conn->query("
        SELECT ur.predmet_id, ur.oddelek_id, ur.dan_v_tednu, ur.ura, ur.ucilnica,
               o.ime AS oddelek_ime, o.letnik
        FROM Urnik ur
        JOIN Oddelek o ON o.id_oddelek = ur.oddelek_id
        WHERE ur.oddelek_id IS NOT NULL
        ORDER BY o.letnik, o.ime, ur.ura
    ");
    foreach ($stmtUrMapAll->fetchAll() as $r) {
        $pid = (int) $r["predmet_id"];
        $oid = (int) $r["oddelek_id"];
        if (!isset($uciteljPredmetOddelkiMap[$pid][$oid])) {
            $uciteljPredmetOddelkiMap[$pid][$oid] = [
                "id" => $oid,
                "ime" => $r["oddelek_ime"],
                "letnik" => (int) $r["letnik"]
            ];
        }
        $slotKey = $pid . "_" . $oid . "_" . $r["dan_v_tednu"];
        $uciteljUrnikSlotMap[$slotKey][] = [
            "ura" => (int) $r["ura"],
            "ucilnica" => $r["ucilnica"]
        ];
    }
    foreach ($uciteljPredmetOddelkiMap as $pid => $map) {
        $uciteljPredmetOddelkiMap[$pid] = array_values($map);
    }
}


// ==============================
// PODATKI ZA ADMINA IN UČITELJA
// ==============================

$predmeti = [];
$ucitelji = [];
$spremembeSeznam = [];
$prostiSeznam = [];

if (in_array($user["vloga"], ["admin", "ucitelj"], true)) {
    // Prihodnji dnevi brez pouka / šolski dogodki (združeni po datumu in razlogu)
    $vrsticeProsti = $conn->query(
        "SELECT d.*, o.ime AS oddelek_ime
         FROM Dan_Brez_Pouka d
         LEFT JOIN Oddelek o ON o.id_oddelek = d.oddelek_id
         WHERE d.datum >= CURDATE()
         ORDER BY d.datum, d.razlog, o.letnik, o.ime"
    )->fetchAll();

    foreach ($vrsticeProsti as $r) {
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
}

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

} elseif ($user["vloga"] === "ucitelj") {

    $predmeti = $uciteljPredmeti;
    $ucitelji = [$user];

    $stmtSprUcitelj = $conn->prepare(
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
           AND COALESCE(s.ucitelj_id, ur.ucitelj_id) = ?
         ORDER BY s.datum, ura_st"
    );
    $stmtSprUcitelj->execute([(int) $user["id_uporabnik"]]);
    $spremembeSeznam = $stmtSprUcitelj->fetchAll();
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


// Povezava na teden (ohrani filter oddelka ali učitelja)
function tedenUrl($teden, $izbranOddelek, $izbranUcitelj = 0)
{
    $url = "urnik.php?teden=" . urlencode($teden);

    if ($izbranUcitelj > 0) {
        $url .= "&ucitelj=" . (int) $izbranUcitelj;
        if ($izbranOddelek > 0) {
            $url .= "&oddelek=" . (int) $izbranOddelek;
        }
    } elseif ($izbranOddelek > 0) {
        $url .= "&oddelek=" . (int) $izbranOddelek;
    }

    return $url;
}


// ==============================
// STRAN
// ==============================

pageStart("Urnik");

?>

<div class="d-flex justify-between align-center flex-wrap gap-3 mb-2">
    <h1 class="m-0">Urnik</h1>
    <?php if ($user["vloga"] !== "dijak"): ?>
        <button type="button" class="btn small outline" id="btnToggleAllUrnikTabs" onclick="toggleAllUrnikTabs()">
            Prikaži vse zavihke
        </button>
    <?php endif; ?>
</div>

<?php if ($user["vloga"] !== "dijak"): ?>
    <div class="admin-tab-nav">
        <button type="button" class="admin-tab-btn active" data-tab="urnik-tabela" onclick="openUrnikTab('urnik-tabela', this)">
            Tedenski urnik
        </button>
        <button type="button" class="admin-tab-btn" data-tab="spremembe" onclick="openUrnikTab('spremembe', this)">
            Sprememba urnika & dogodki <span class="tab-badge"><?= count($spremembeSeznam) + count($prostiSeznam) ?></span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="ocenjevanje" onclick="openUrnikTab('ocenjevanje', this)">
            Razpis pisnega ocenjevanja <span class="tab-badge"><?= count($prihodnjaOcenjevanja) ?></span>
        </button>
        <?php if ($user["vloga"] === "admin"): ?>
            <button type="button" class="admin-tab-btn" data-tab="admin-urnik" onclick="openUrnikTab('admin-urnik', this)">
                Dodaj uro & dnevi brez pouka <span class="tab-badge"><?= count($prostiSeznam) ?></span>
            </button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($user["vloga"] === "dijak" && !empty($user["oddelek_id"])): ?>

    <p>
        Oddelek:
        <strong>
            <?php foreach ($oddelki as $o): ?>
                <?= $o["id_oddelek"] == $user["oddelek_id"] ? e($o["ime"] . " (" . $o["program"] . ")") : "" ?>
            <?php endforeach; ?>
        </strong>
    </p>

<?php endif; ?>


<?php if ($user["vloga"] === "admin"): ?>

    <div id="tab-admin-urnik" class="tab-content">
    <!-- DODAJANJE URE -->

    <section class="panel mt-2">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Redni urnik</span>
                <h2 class="m-0 mt-1">Dodaj redno uro v tedenski urnik</h2>
                <p class="off-hint m-0 mt-1">Vnesite stalno tedensko uro pouka za izbrani predmet, profesorja in oddelek.</p>
            </div>
        </div>

        <form method="post" class="off-form">
            <input type="hidden" name="action" value="dodaj">

            <div class="schedule-change-grid-top">
                <label>
                    Predmet
                    <select name="predmet_id" required>
                        <option value="">-- Izberi predmet --</option>
                        <?php foreach ($predmeti as $p): ?>
                            <option value="<?= (int) $p["id_predmet"] ?>"><?= e($p["naziv"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Učitelj
                    <select name="ucitelj_id" required>
                        <option value="">-- Izberi učitelja --</option>
                        <?php foreach ($ucitelji as $u): ?>
                            <option value="<?= (int) $u["id_uporabnik"] ?>"><?= e($u["priimek"] . " " . $u["ime"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Dan v tednu
                    <select name="dan" required>
                        <?php foreach ($days as $day): ?>
                            <option value="<?= e($day) ?>"><?= e($day) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Šolska ura
                    <select name="ura" required>
                        <option value="0">0. ura (predura)</option>
                        <?php for ($uSt = 1; $uSt <= 10; $uSt++): ?>
                            <option value="<?= $uSt ?>" <?= $uSt === 1 ? "selected" : "" ?>><?= $uSt ?>. ura</option>
                        <?php endfor; ?>
                    </select>
                </label>
            </div>

            <div class="exam-schedule-grid-bottom mt-3">
                <label>
                    Oddelek / razred
                    <select name="oddelek_id">
                        <option value="">Brez oddelka</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= (int) $o["id_oddelek"] ?>" <?= (int) $o["id_oddelek"] === $izbranOddelek ? "selected" : "" ?>>
                                <?= e($o["ime"] . " – " . $o["program"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Učilnica
                    <input type="text" name="ucilnica" placeholder="npr. B204">
                </label>

                <div class="office-self-btn-wrap">
                    <button class="btn blue btn-h38 nowrap">+ Dodaj uro v urnik</button>
                </div>
            </div>
        </form>
    </section>


    <!-- DNEVI BREZ POUKA -->

    <section class="panel mt-4">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Šolski koledar in dogodki</span>
                <h2 class="m-0 mt-1">Dan brez pouka / šolski dogodek po letnikih</h2>
                <p class="off-hint m-0 mt-1">
                    Dodajte dan, ko pouka ni (npr. športni dan, kulturni dan, ekskurzija, prosti dan) za celo šolo ali za izbrane oddelke.
                </p>
            </div>
        </div>

        <form method="post" class="off-form">

            <input type="hidden" name="action" value="dodaj_prosto">

            <div class="schedule-change-grid-bottom">
                <div class="form-row-2">
                    <label>
                        Datum dogodka
                        <input type="date" name="datum" value="<?= date("Y-m-d") ?>" required>
                    </label>

                    <label>
                        Razlog / naziv dogodka
                        <input type="text" name="razlog" maxlength="150" placeholder="npr. Športni dan" required>
                    </label>
                </div>

                <div class="office-self-btn-wrap">
                    <button class="btn blue btn-h38 nowrap">+ Dodaj dan brez pouka</button>
                </div>
            </div>

            <div class="off-classes mt-3">

                <span class="off-label">Za katere razrede velja?</span>

                <label class="off-all">
                    <input type="checkbox" id="prosto_vsi" name="vsi_oddelki" value="1" checked onchange="prostoVsi(this)">
                    Vsi razredi (cela šola)
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

        </form>


        <div class="panel-header-actions mt-4 mb-2">
            <h3 class="m-0">Prihodnji dnevi brez pouka</h3>
            <span class="badge-info"><?= count($prostiSeznam) ?> dni</span>
        </div>

        <?php if (!$prostiSeznam): ?>
            <div class="prazno-stanje">
                <p class="m-0">Trenutno ni določenih dni brez pouka.</p>
            </div>
        <?php else: ?>
            <div class="tabela-ovoj">
                <table>
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Razlog / naziv dogodka</th>
                            <th>Oddelki</th>
                            <th class="text-right">Dejanja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $dnevi = ["Nedelja", "Ponedeljek", "Torek", "Sreda", "Četrtek", "Petek", "Sobota"];
                        ?>
                        <?php foreach ($prostiSeznam as $p): ?>
                            <?php
                            $dt = new DateTime($p["datum"]);
                            $komu = $p["vsi"] ? "Vsi razredi (cela šola)" : implode(", ", $p["oddelki"]);
                            ?>
                            <tr>
                                <td class="nowrap">
                                    <strong><?= e($dt->format("j. n. Y")) ?></strong>
                                    <small class="text-muted d-block"><?= e($dnevi[(int) $dt->format("w")]) ?></small>
                                </td>
                                <td><strong><?= e($p["razlog"]) ?></strong></td>
                                <td><span class="badge-class-chip"><?= e($komu) ?></span></td>
                                <td class="text-right nowrap">
                                    <div class="d-inline-flex items-center gap-2">
                                        <a class="btn small btn-white" href="urnik.php?teden=<?= e((clone $dt)->modify("monday this week")->format("Y-m-d")) ?>">Poglej v urniku</a>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="izbrisi_prosto">
                                            <input type="hidden" name="ids" value="<?= e(implode(",", $p["ids"])) ?>">
                                            <button class="gumb-odstrani" onclick="return confirm('Vrnem pouk za ta dan?')">Vrni pouk</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </section>

    <script>
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
    </div>

<?php endif; ?>

<?php if (in_array($user["vloga"], ["ucitelj", "admin"], true)): ?>

    <div id="tab-spremembe" class="tab-content">
    <section class="panel mt-2">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Upravljanje sprememb in šolskih dogodkov</span>
                <h2 class="m-0 mt-1">Sprememba urnika, nadomeščanje in šolski dogodki</h2>
                <p class="off-hint m-0 mt-1">
                    Dodajte dodatno uro, nadomeščanje, spremembo učilnice ali celodnevni šolski dogodek (npr. športni dan, kulturni dan, ekskurzija) za posamezen razred ali za <strong>vse oddelke (celo šolo)</strong>.
                </p>
            </div>
        </div>

        <form method="post" class="off-form">
            <input type="hidden" name="action" value="dodaj_spremembo">
            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">

            <div class="mb-3">
                <span class="off-label d-block mb-2">Izberite vrsto vnosa v urnik</span>
                <div class="schedule-mode-grid">
                    <label class="schedule-mode-card">
                        <input type="radio" name="obseg" value="datum" checked onchange="preklopiObseg(this.value)">
                        <span class="schedule-mode-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </span>
                        <span class="schedule-mode-text">
                            <span class="schedule-mode-title">Posamezna šolska ura</span>
                            <span class="schedule-mode-sub">Nadomeščanje, dodatna ali odpadla ura</span>
                        </span>
                    </label>

                    <label class="schedule-mode-card">
                        <input type="radio" name="obseg" value="cel_dan" onchange="preklopiObseg(this.value)">
                        <span class="schedule-mode-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </span>
                        <span class="schedule-mode-text">
                            <span class="schedule-mode-title">Odpade cel dan / dogodek</span>
                            <span class="schedule-mode-sub">Športni dan, kulturni dan, prost dan</span>
                        </span>
                    </label>

                    <label class="schedule-mode-card">
                        <input type="radio" name="obseg" value="trajno" onchange="preklopiObseg(this.value)">
                        <span class="schedule-mode-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                        </span>
                        <span class="schedule-mode-text">
                            <span class="schedule-mode-title">Trajno v tedenski urnik</span>
                            <span class="schedule-mode-sub">Stalna ura vsak teden na ta dan</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="schedule-form-box">
                <div class="schedule-change-grid-top" id="grid_vrstica_zgoraj">
                    <label>
                        Datum
                        <input type="date" id="sprememba_datum" name="datum" required value="<?= e($ponedeljek->format("Y-m-d")) ?>">
                    </label>

                    <label>
                        Razred / oddelek
                        <select id="sprememba_oddelek" name="oddelek_id" required>
                            <option value="vsi">Vsi oddelki (cela šola)</option>
                            <?php foreach ($oddelki as $o): ?>
                                <option value="<?= e($o["id_oddelek"]) ?>" <?= ($izbranOddelek === (int) $o["id_oddelek"]) ? "selected" : "" ?>>
                                    <?= e($o["ime"] . " – " . $o["program"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label id="polje_vrsta">
                        Vrsta spremembe / dogodka
                        <select id="sprememba_vrsta" name="vrsta" onchange="preklopiVrstoSpremembe()">
                            <option value="dodatna">Dodatna ura</option>
                            <option value="nadomescanje">Nadomeščanje</option>
                            <option value="nadomestna">Nadomestna ura</option>
                            <option value="odpade_ura_slot">Odpade posamezna ura</option>
                            <option value="dogodek_prosto">Odpade cel dan / šolski dogodek</option>
                        </select>
                    </label>

                    <label id="polje_ura">
                        Šolska ura / trajanje
                        <select id="sprememba_ura" name="ura" required onchange="preklopiUroSpremembe(this.value)">
                            <option value="cel_dan">Cel dan (vse ure pouka)</option>
                            <option value="0">0. ura (predura)</option>
                            <?php for ($uSt = 1; $uSt <= 10; $uSt++): ?>
                                <option value="<?= $uSt ?>" <?= $uSt === 1 ? "selected" : "" ?>><?= $uSt ?>. ura</option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>

                <div class="schedule-change-grid-mid mt-3" id="polje_predmet_ucitelj">
                    <label>
                        Predmet
                        <select id="sprememba_predmet" name="predmet_id" required>
                            <option value="">-- Izberi predmet --</option>
                            <?php foreach ($predmeti as $p): ?>
                                <option value="<?= e($p["id_predmet"]) ?>"><?= e($p["naziv"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Učitelj
                        <select id="sprememba_ucitelj" name="ucitelj_id" required>
                            <?php if ($user["vloga"] === "admin"): ?>
                                <option value="">-- Izberi učitelja --</option>
                            <?php endif; ?>
                            <?php foreach ($ucitelji as $u): ?>
                                <option value="<?= e($u["id_uporabnik"]) ?>" <?= ((int) $u["id_uporabnik"] === (int) $user["id_uporabnik"]) ? "selected" : "" ?>>
                                    <?= e($u["priimek"] . " " . $u["ime"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Učilnica
                        <input type="text" id="sprememba_ucilnica" name="ucilnica" placeholder="npr. B204">
                    </label>
                </div>

                <div class="schedule-change-grid-bottom mt-3">
                    <label id="polje_opomba">
                        <span id="sprememba_opomba_label">Opomba (neobvezno, npr. menjava učilnice ali nadomeščanje)</span>
                        <input type="text" id="sprememba_opomba" name="opomba" maxlength="200" placeholder="npr. Športni dan / Prestava v učilnico B210 / nadomeščanje ...">
                    </label>

                    <div class="office-self-btn-wrap">
                        <button type="submit" class="btn blue btn-h38 nowrap">+ Dodaj v urnik</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="panel-header-actions mt-4 mb-2">
            <h3 class="m-0">Prihodnje spremembe urnika in šolski dogodki</h3>
            <span class="badge-info"><?= count($spremembeSeznam) + count($prostiSeznam) ?> zapisov</span>
        </div>

        <?php if (empty($spremembeSeznam) && empty($prostiSeznam)): ?>
            <div class="prazno-stanje">
                <p class="m-0">Trenutno ni zabeleženih prihodnjih sprememb urnika ali šolskih dogodkov.</p>
            </div>
        <?php else: ?>
            <?php
            $dneviImena = ["Nedelja", "Ponedeljek", "Torek", "Sreda", "Četrtek", "Petek", "Sobota"];
            ?>
            <div class="tabela-ovoj">
                <table>
                    <thead>
                        <tr>
                            <th>Datum in ura</th>
                            <th>Vrsta</th>
                            <th>Razred</th>
                            <th>Podrobnosti / predmet / dogodek</th>
                            <th class="text-right">Dejanja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prostiSeznam as $p): ?>
                            <?php
                            $dtP = new DateTime($p["datum"]);
                            $komuP = $p["vsi"] ? "Vsi oddelki (cela šola)" : implode(", ", $p["oddelki"]);
                            ?>
                            <tr>
                                <td class="nowrap">
                                    <strong><?= e($dtP->format("j. n. Y")) ?></strong>
                                    <small class="text-muted d-block"><?= e($dneviImena[(int) $dtP->format("w")]) ?> · Cel dan</small>
                                </td>
                                <td><span class="badge-warn py-1 px-2 font-bold text-xs">Odpade cel dan / dogodek</span></td>
                                <td><span class="badge-class-chip"><?= e($komuP) ?></span></td>
                                <td><strong><?= e($p["razlog"]) ?></strong></td>
                                <td class="text-right nowrap">
                                    <div class="d-inline-flex items-center gap-2">
                                        <a class="btn small btn-white" href="urnik.php?teden=<?= e((clone $dtP)->modify("monday this week")->format("Y-m-d")) ?>">Poglej v urniku</a>
                                        <?php if ($user["vloga"] === "admin"): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="action" value="izbrisi_prosto">
                                                <input type="hidden" name="ids" value="<?= e(implode(",", $p["ids"])) ?>">
                                                <button class="gumb-odstrani" onclick="return confirm('Vrnem pouk za ta dan?')">Vrni pouk</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
                            <tr>
                                <td class="nowrap">
                                    <strong><?= e($sDatum->format("j. n. Y")) ?></strong>
                                    <small class="text-muted d-block"><?= e($dneviImena[$sDanIdx] . " · " . $s["ura_st"] . ". ura") ?></small>
                                </td>
                                <td>
                                    <?php if ($s["tip"] === "odpade"): ?>
                                        <span class="badge-danger py-1 px-2 font-bold text-xs">Odpadla ura</span>
                                    <?php else: ?>
                                        <span class="schedule-extra-badge"><?= e($vrstaTekst) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge-class-chip"><?= e($s["oddelek_ime"] ?: "—") ?></span></td>
                                <td>
                                    <strong><?= e($s["predmet_naziv"]) ?></strong>
                                    <?php if ($s["tip"] !== "odpade"): ?>
                                        <span class="text-muted text-sm">&middot; <?= e($s["ucitelj_priimek"] . " " . $s["ucitelj_ime"]) ?><?= !empty($s["ucilnica"]) ? " (" . e($s["ucilnica"]) . ")" : "" ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($s["opomba"])): ?>
                                        <small class="text-muted d-block"><?= e($s["opomba"]) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right nowrap">
                                    <div class="d-inline-flex items-center gap-2">
                                        <a class="btn small btn-white" href="urnik.php?teden=<?= e((clone $sDatum)->modify("monday this week")->format("Y-m-d")) ?>&oddelek=<?= e($s["oddelek_id"]) ?>">Poglej v urniku</a>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="izbrisi_spremembo">
                                            <input type="hidden" name="id" value="<?= e($s["id_sprememba"]) ?>">
                                            <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                            <button class="gumb-odstrani" onclick="return confirm('<?= $s["tip"] === "odpade" ? "Vrnem to uro v urnik?" : "Odstranim to dodatno uro?" ?>')">
                                                <?= $s["tip"] === "odpade" ? "Vrni uro" : "Odstrani" ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </section>

    <script>
    function preklopiObseg(val) {
        var selVrsta = document.getElementById('sprememba_vrsta');
        var selUra = document.getElementById('sprememba_ura');

        if (val === 'cel_dan') {
            if (selVrsta) selVrsta.value = 'dogodek_prosto';
            if (selUra) selUra.value = 'cel_dan';
        } else if (val === 'datum') {
            if (selVrsta && selVrsta.value === 'dogodek_prosto') {
                selVrsta.value = 'dodatna';
            }
            if (selUra && selUra.value === 'cel_dan') {
                selUra.value = '1';
            }
        } else if (val === 'trajno') {
            if (selUra && selUra.value === 'cel_dan') {
                selUra.value = '1';
            }
        }
        osveziPrikazSpremembe();
    }

    function preklopiVrstoSpremembe() {
        var selVrsta = document.getElementById('sprememba_vrsta');
        var selUra = document.getElementById('sprememba_ura');
        var radioCelDan = document.querySelector('input[name="obseg"][value="cel_dan"]');
        var radioDatum = document.querySelector('input[name="obseg"][value="datum"]');

        if (selVrsta && selVrsta.value === 'dogodek_prosto') {
            if (selUra) selUra.value = 'cel_dan';
            if (radioCelDan) radioCelDan.checked = true;
        } else {
            if (selUra && selUra.value === 'cel_dan') {
                selUra.value = '1';
            }
            if (radioCelDan && radioCelDan.checked && radioDatum) {
                radioDatum.checked = true;
            }
        }
        osveziPrikazSpremembe();
    }

    function preklopiUroSpremembe(val) {
        var selVrsta = document.getElementById('sprememba_vrsta');
        var radioCelDan = document.querySelector('input[name="obseg"][value="cel_dan"]');
        var radioDatum = document.querySelector('input[name="obseg"][value="datum"]');

        if (val === 'cel_dan') {
            if (selVrsta) selVrsta.value = 'dogodek_prosto';
            if (radioCelDan) radioCelDan.checked = true;
        } else {
            if (selVrsta && selVrsta.value === 'dogodek_prosto') {
                selVrsta.value = 'dodatna';
            }
            if (radioCelDan && radioCelDan.checked && radioDatum) {
                radioDatum.checked = true;
            }
        }
        osveziPrikazSpremembe();
    }

    function osveziPrikazSpremembe() {
        var obsegTrajno = document.querySelector('input[name="obseg"][value="trajno"]');
        var obsegCelDan = document.querySelector('input[name="obseg"][value="cel_dan"]');
        var jeTrajno = !!(obsegTrajno && obsegTrajno.checked);
        var selVrsta = document.getElementById('sprememba_vrsta');
        var selUra = document.getElementById('sprememba_ura');

        var jeCelDan = !jeTrajno && (
            (obsegCelDan && obsegCelDan.checked) ||
            (selVrsta && selVrsta.value === 'dogodek_prosto') ||
            (selUra && selUra.value === 'cel_dan')
        );
        var jeOdpovedUre = !jeTrajno && !jeCelDan && selVrsta && selVrsta.value === 'odpade_ura_slot';

        var poljeVrsta = document.getElementById('polje_vrsta');
        var poljeMid = document.getElementById('polje_predmet_ucitelj');
        var poljeOpomba = document.getElementById('polje_opomba');
        var selPredmet = document.getElementById('sprememba_predmet');
        var selUcitelj = document.getElementById('sprememba_ucitelj');
        var inpOpomba = document.getElementById('sprememba_opomba');
        var lblOpomba = document.getElementById('sprememba_opomba_label');

        if (poljeVrsta) poljeVrsta.classList.toggle('d-none', jeTrajno);
        if (poljeOpomba) poljeOpomba.classList.toggle('d-none', jeTrajno);

        var skrijPredmetUcitelj = jeCelDan || jeOdpovedUre;
        if (poljeMid) poljeMid.classList.toggle('d-none', skrijPredmetUcitelj);
        if (selPredmet) selPredmet.required = !skrijPredmetUcitelj;
        if (selUcitelj) selUcitelj.required = !skrijPredmetUcitelj;
        if (inpOpomba) inpOpomba.required = jeCelDan;

        if (lblOpomba) {
            if (jeCelDan) {
                lblOpomba.textContent = 'Razlog / naziv dogodka za cel dan (obvezno, npr. Športni dan, Kulturni dan, Ekskurzija, Prosti dan)';
            } else if (jeOdpovedUre) {
                lblOpomba.textContent = 'Razlog odpovedi ure (neobvezno, npr. odsotnost profesorja)';
            } else {
                lblOpomba.textContent = 'Opomba (neobvezno, npr. menjava učilnice ali nadomeščanje)';
            }
        }
    }
    </script>
    </div>

    <?php
    $izbiraPredmetovOcenjevanje = array_values(array_filter(
        $user["vloga"] === "admin" ? $predmeti : $uciteljPredmeti,
        fn($pr) => mb_strtolower(trim($pr["naziv"] ?? ""), "UTF-8") !== "razredna ura"
    ));
    $dneviImenaPolno = ["Nedelja", "Ponedeljek", "Torek", "Sreda", "Četrtek", "Petek", "Sobota"];
    ?>

    <!-- RAZPIS PISNEGA OCENJEVANJA (UČITELJ / ADMIN) -->
    <div id="tab-ocenjevanje" class="tab-content">
    <section class="panel mt-2" id="pisno-ocenjevanje">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Ocenjevanja znanja</span>
                <h2 class="m-0 mt-1">Razpis pisnega ocenjevanja znanja</h2>
                <p class="off-hint m-0 mt-1">
                    Določite pisno ocenjevanje za izbrani predmet, razred, datum in šolsko uro. Dijaki bodo pisno ocenjevanje videli neposredno na svojem urniku.
                </p>
            </div>
        </div>

        <form method="post" class="off-form">
            <input type="hidden" name="action" value="dodaj_ocenjevanje">
            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">

            <div class="schedule-form-box">
                <div class="exam-schedule-grid-top">
                    <label>
                        Predmet
                        <select id="oc_predmet" name="predmet_id" required onchange="posodobiOcenjevanjeObrazec()">
                            <option value="">-- Izberi predmet --</option>
                            <?php foreach ($izbiraPredmetovOcenjevanje as $p): ?>
                                <option value="<?= (int) $p["id_predmet"] ?>"><?= e($p["naziv"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Razred
                        <select id="oc_oddelek" name="oddelek_id" required onchange="posodobiOcenjevanjeUro()">
                            <option value="">-- Izberi razred --</option>
                            <?php foreach ($oddelki as $o): ?>
                                <option value="<?= (int) $o["id_oddelek"] ?>" <?= ($izbranOddelek === (int) $o["id_oddelek"]) ? "selected" : "" ?>>
                                    <?= e($o["ime"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Datum ocenjevanja
                        <input type="date" id="oc_datum" name="datum" value="<?= e($ponedeljek->format("Y-m-d")) ?>" required onchange="posodobiOcenjevanjeUro()">
                    </label>

                    <label>
                        Šolska ura
                        <select id="oc_ura" name="ura" required>
                            <?php for ($uSt = 0; $uSt <= 10; $uSt++): ?>
                                <option value="<?= $uSt ?>" <?= $uSt === 1 ? "selected" : "" ?>><?= $uSt ?>. ura</option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>

                <div id="oc_urnik_namig" class="teacher-class-note d-none"></div>

                <div class="exam-schedule-grid-bottom mt-3">
                    <label>
                        Naslov pisnega ocenjevanja
                        <input type="text" id="oc_naslov" name="naslov" maxlength="150" placeholder="npr. 1. pisno ocenjevanje znanja" required>
                    </label>

                    <label>
                        Obseg snovi / opomba (neobvezno)
                        <input type="text" id="oc_opis" name="opis" maxlength="255" placeholder="npr. Kvadratna funkcija, enačbe in neenačbe">
                    </label>

                    <div class="office-self-btn-wrap">
                        <button type="submit" class="btn blue btn-h38 nowrap">+ Dodaj ocenjevanje</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="panel-header-actions mt-4 mb-2">
            <h3 class="m-0">Prihodnja pisna ocenjevanja</h3>
            <span class="badge-info"><span id="st_ocenjevanj_prikaz"><?= count($prihodnjaOcenjevanja) ?></span> ocenjevanj</span>
        </div>

        <?php if (empty($prihodnjaOcenjevanja)): ?>
            <div class="prazno-stanje">
                <p class="m-0">Trenutno ni razpisanih prihodnjih pisnih ocenjevanj.</p>
            </div>
        <?php else: ?>
            <div class="enrollment-toolbar mb-3">
                <input
                    type="text"
                    id="filter_ocenjevanja_search"
                    placeholder="Išči po predmetu, razredu, naslovu ali profesorju..."
                    class="enrollment-search-box"
                    oninput="filtrirajPrihodnjaOcenjevanja()"
                >
            </div>

            <div class="tabela-ovoj admin-table-scroll">
                <table id="tabela_prihodnjih_ocenjevanj">
                    <thead>
                        <tr>
                            <th class="col-num">#</th>
                            <th>Datum in ura</th>
                            <th>Razred</th>
                            <th>Predmet in profesor</th>
                            <th>Naslov in obseg snovi</th>
                            <th class="text-right">Dejanja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prihodnjaOcenjevanja as $idxOc => $oc): ?>
                            <?php
                            $ocDt = new DateTime($oc["datum"]);
                            $ocDanIme = $dneviImenaPolno[(int) $ocDt->format("w")];
                            $ocPonedeljek = (clone $ocDt)->modify("monday this week")->format("Y-m-d");
                            $ocSearch = mb_strtolower($oc["naslov"] . " " . $oc["predmet_naziv"] . " " . $oc["oddelek_ime"] . " " . ($oc["opis"] ?? "") . " " . $oc["ucitelj_priimek"] . " " . $oc["ucitelj_ime"], "UTF-8");
                            ?>
                            <tr class="ocenjevanje-row" data-search="<?= e($ocSearch) ?>">
                                <td class="col-num text-muted font-bold ocenjevanje-row-idx"><?= $idxOc + 1 ?>.</td>
                                <td class="nowrap">
                                    <strong><?= e($ocDt->format("j. n. Y")) ?></strong>
                                    <small class="text-muted d-block"><?= e($ocDanIme . " · " . $oc["ura"] . ". ura") ?></small>
                                </td>
                                <td><span class="badge-class-chip"><?= e($oc["oddelek_ime"]) ?></span></td>
                                <td>
                                    <strong><?= e($oc["predmet_naziv"]) ?></strong>
                                    <small class="text-muted d-block"><?= e($oc["ucitelj_priimek"] . " " . $oc["ucitelj_ime"]) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($oc["naslov"]) ?></strong>
                                    <?php if (!empty($oc["opis"])): ?>
                                        <small class="text-muted d-block"><?= e($oc["opis"]) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right nowrap">
                                    <div class="d-inline-flex items-center gap-2">
                                        <a class="btn small btn-white" href="<?= e(tedenUrl($ocPonedeljek, (int) $oc["oddelek_id"])) ?>">Poglej v urniku</a>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="izbrisi_ocenjevanje">
                                            <input type="hidden" name="id" value="<?= (int) $oc["id_ocenjevanje"] ?>">
                                            <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                            <button type="submit" class="gumb-odstrani" onclick="return confirm('Odstranim pisno ocenjevanje z urnika?')">
                                                Odstrani
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <script>
    const uciteljPredmetOddelkiMap = <?= json_encode($uciteljPredmetOddelkiMap, JSON_UNESCAPED_UNICODE) ?>;
    const uciteljUrnikSlotMap = <?= json_encode($uciteljUrnikSlotMap, JSON_UNESCAPED_UNICODE) ?>;
    const dneviTednaMap = {1: 'Ponedeljek', 2: 'Torek', 3: 'Sreda', 4: 'Četrtek', 5: 'Petek'};

    function posodobiOcenjevanjeObrazec() {
        const selP = document.getElementById('oc_predmet');
        const selO = document.getElementById('oc_oddelek');
        if (!selP || !selO) return;

        const pid = parseInt(selP.value, 10);
        const dovoljeni = (pid && uciteljPredmetOddelkiMap[pid]) ? uciteljPredmetOddelkiMap[pid].map(x => x.id) : [];

        Array.from(selO.options).forEach(opt => {
            if (!opt.value) return;
            const oid = parseInt(opt.value, 10);
            const show = (!pid || dovoljeni.length === 0 || dovoljeni.includes(oid));
            opt.hidden = !show;
            opt.disabled = !show;
        });

        const cur = selO.selectedOptions[0];
        if (cur && cur.hidden) {
            selO.value = '';
        }
        if (!selO.value && dovoljeni.length === 1) {
            selO.value = String(dovoljeni[0]);
        }

        posodobiOcenjevanjeUro();
    }

    function filtrirajPrihodnjaOcenjevanja() {
        const inp = document.getElementById('filter_ocenjevanja_search');
        const q = inp ? inp.value.trim().toLowerCase() : '';
        const rows = document.querySelectorAll('.ocenjevanje-row');
        let visible = 0;

        rows.forEach(r => {
            const s = (r.getAttribute('data-search') || '').toLowerCase();
            if (!q || s.includes(q)) {
                r.classList.remove('d-none');
                visible++;
                const idx = r.querySelector('.ocenjevanje-row-idx');
                if (idx) idx.textContent = visible + '.';
            } else {
                r.classList.add('d-none');
            }
        });

        const stEl = document.getElementById('st_ocenjevanj_prikaz');
        if (stEl) stEl.textContent = visible;
    }

    function posodobiOcenjevanjeUro() {
        const selP = document.getElementById('oc_predmet');
        const selO = document.getElementById('oc_oddelek');
        const inpD = document.getElementById('oc_datum');
        const selU = document.getElementById('oc_ura');
        const namig = document.getElementById('oc_urnik_namig');
        if (!selP || !selO || !inpD || !selU) return;

        const pid = parseInt(selP.value, 10);
        const oid = parseInt(selO.value, 10);
        const datumVal = inpD.value;

        if (!pid || !oid || !datumVal) {
            if (namig) namig.classList.add('d-none');
            return;
        }

        const parts = datumVal.split('-');
        if (parts.length !== 3) return;
        const dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        const dayNum = dt.getDay();
        const danIme = dneviTednaMap[dayNum] || '';

        const key = pid + '_' + oid + '_' + danIme;
        const slots = uciteljUrnikSlotMap[key] || [];

        if (slots.length > 0) {
            selU.value = String(slots[0].ura);
            if (namig) {
                const ureTekst = slots.map(s => s.ura + '. ura' + (s.ucilnica ? ' (' + s.ucilnica + ')' : '')).join(', ');
                namig.textContent = 'Redna ura v urniku za izbrani razred na dan (' + danIme + '): ' + ureTekst + '.';
                namig.classList.remove('d-none');
            }
        } else {
            if (namig) {
                namig.classList.add('d-none');
            }
        }
    }
    </script>
    </div>

<?php endif; ?>


<!-- URNIK -->
<div id="tab-urnik-tabela" class="<?= $user["vloga"] !== "dijak" ? "tab-content active" : "" ?>">

<?php if ($user["vloga"] === "admin"): ?>
    <form method="get" class="urnik-filter-bar">
        <input type="hidden" name="teden" value="<?= e($tedenVrednost) ?>">
        <div class="urnik-filter-left d-flex flex-wrap gap-3 items-center">
            <div class="d-flex items-center gap-2">
                <label class="urnik-filter-label" for="filter_urnik_oddelek">Oddelek:</label>
                <select id="filter_urnik_oddelek" name="oddelek" class="urnik-filter-select" onchange="if(this.value !== '0'){ document.getElementById('filter_urnik_ucitelj').value = '0'; } this.form.submit()">
                    <option value="0" <?= ($izbranOddelek <= 0 || $izbranUcitelj > 0) ? "selected" : "" ?>>-- Izberi oddelek --</option>
                    <?php foreach ($oddelki as $o): ?>
                        <option
                            value="<?= (int) $o["id_oddelek"] ?>"
                            <?= ((int) $o["id_oddelek"] === $izbranOddelek && $izbranUcitelj <= 0) ? "selected" : "" ?>
                        >
                            <?= e($o["ime"] . " – " . $o["program"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex items-center gap-2">
                <label class="urnik-filter-label" for="filter_urnik_ucitelj">Učitelj:</label>
                <select id="filter_urnik_ucitelj" name="ucitelj" class="urnik-filter-select" onchange="if(this.value !== '0'){ document.getElementById('filter_urnik_oddelek').value = '0'; } this.form.submit()">
                    <option value="0" <?= $izbranUcitelj <= 0 ? "selected" : "" ?>>-- Izberi učitelja --</option>
                    <?php foreach ($vsiUcitelji as $u): ?>
                        <option
                            value="<?= (int) $u["id_uporabnik"] ?>"
                            <?= (int) $u["id_uporabnik"] === $izbranUcitelj ? "selected" : "" ?>
                        >
                            <?= e($u["priimek"] . " " . $u["ime"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <?php if ($izbranUciteljPodatki): ?>
                <span class="badge-ok">
                    Urnik učitelja: <?= e($izbranUciteljPodatki["priimek"] . " " . $izbranUciteljPodatki["ime"]) ?>
                </span>
            <?php else: ?>
                <span class="badge-info">Pregled tedenskega urnika</span>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($izbranUciteljPodatki): ?>
        <div class="info-banner mb-3">
            <div>
                <strong class="text-primary text-md">
                    <?= e($izbranUciteljPodatki["priimek"] . " " . $izbranUciteljPodatki["ime"]) ?>
                </strong>
                <span class="text-muted text-sm ml-2">Tedenski razpored pouka pri vseh oddelkih</span>
            </div>
            <div class="d-flex flex-wrap gap-3 text-sm text-muted">
                <?php if (!empty($izbranUciteljPodatki["kabinet"])): ?>
                    <span>Kabinet: <strong class="text-dark"><?= e($izbranUciteljPodatki["kabinet"]) ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($izbranUciteljPodatki["govorilne_ure"])): ?>
                    <span>Govorilne ure: <strong class="text-dark"><?= e($izbranUciteljPodatki["govorilne_ure"]) ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($user["vloga"] === "ucitelj"): ?>
    <form method="get" class="urnik-filter-bar">
        <input type="hidden" name="teden" value="<?= e($tedenVrednost) ?>">
        <div class="urnik-filter-left">
            <label class="urnik-filter-label" for="filter_urnik_oddelek">Izbrani oddelek:</label>
            <select id="filter_urnik_oddelek" name="oddelek" class="urnik-filter-select" onchange="this.form.submit()">
                <option value="0">Moj urnik (vsi moji oddelki)</option>
                <?php foreach ($oddelki as $o): ?>
                    <option
                        value="<?= (int) $o["id_oddelek"] ?>"
                        <?= (int) $o["id_oddelek"] === $izbranOddelek ? "selected" : "" ?>
                    >
                        <?= e($o["ime"] . " – " . $o["program"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <span class="badge-info">Pregled tedenskega urnika</span>
    </form>
<?php endif; ?>

<?php
$byCell = [];
$maxUra = 0;
$minUra = 99;

foreach ($entries as $entry) {
    $entry["ocenjevanja"] = [];
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
        "predmet_id" => $s["predmet_id"],
        "ucitelj_id" => $s["ucitelj_id"],
        "naziv" => $s["predmet_naziv"],
        "ime" => $s["ucitelj_ime"],
        "priimek" => $s["ucitelj_priimek"],
        "ucilnica" => $s["ucilnica"],
        "oddelek" => $s["oddelek_ime"],
        "oddelek_id" => $s["oddelek_id"],
        "ocenjevanja" => []
    ];

    $maxUra = max($maxUra, $uraSt);
    $minUra = min($minUra, $uraSt);
}

// Pripni pisna ocenjevanja v celice urnika za izbrani teden
foreach ($ocenjevanjaTedna as $oc) {
    $dnevIdx = (int) (new DateTime($oc["datum"]))->format("N") - 1;
    if (!isset($days[$dnevIdx])) {
        continue;
    }
    $danIme = $days[$dnevIdx];
    $uraSt = (int) $oc["ura"];
    $pripeto = false;

    if (!empty($byCell[$uraSt][$danIme])) {
        foreach ($byCell[$uraSt][$danIme] as &$celicaVnos) {
            $ujemanjeOddelek = (int) ($celicaVnos["oddelek_id"] ?? 0) === (int) $oc["oddelek_id"];
            $ujemanjePredmet = (int) ($celicaVnos["predmet_id"] ?? 0) === (int) $oc["predmet_id"];
            if ($ujemanjeOddelek && $ujemanjePredmet) {
                $celicaVnos["ocenjevanja"][] = $oc;
                $pripeto = true;
                break;
            }
        }
        unset($celicaVnos);

        if (!$pripeto) {
            foreach ($byCell[$uraSt][$danIme] as &$celicaVnos) {
                if ((int) ($celicaVnos["oddelek_id"] ?? 0) === (int) $oc["oddelek_id"]) {
                    $celicaVnos["ocenjevanja"][] = $oc;
                    $pripeto = true;
                    break;
                }
            }
            unset($celicaVnos);
        }
    }

    if (!$pripeto) {
        $byCell[$uraSt][$danIme][] = [
            "exam_only" => true,
            "predmet_id" => $oc["predmet_id"],
            "ucitelj_id" => $oc["ucitelj_id"],
            "oddelek_id" => $oc["oddelek_id"],
            "naziv" => $oc["predmet_naziv"],
            "ime" => $oc["ucitelj_ime"],
            "priimek" => $oc["ucitelj_priimek"],
            "ucilnica" => "",
            "oddelek" => $oc["oddelek_ime"],
            "ocenjevanja" => [$oc]
        ];
        $maxUra = max($maxUra, $uraSt);
        $minUra = min($minUra, $uraSt);
    }
}

$imaVnose = (bool) $entries || (bool) $dodatneUre || (bool) $ocenjevanjaTedna;

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

    <a class="btn small" href="<?= e(tedenUrl($tedenPrej, $izbranOddelek, $izbranUcitelj)) ?>">&larr; Prejšnji teden</a>

    <div class="week-title">
        <?= e($ponedeljek->format("j. n.") . " – " . $mesecDo->format("j. n. Y")) ?>
        <?php if ($tedenVrednost !== $tedenTa): ?>
            <a class="week-today" href="<?= e(tedenUrl($tedenTa, $izbranOddelek, $izbranUcitelj)) ?>">Ta teden</a>
        <?php endif; ?>
    </div>

    <a class="btn small" href="<?= e(tedenUrl($tedenNaprej, $izbranOddelek, $izbranUcitelj)) ?>">Naslednji teden &rarr;</a>

</div>

<div class="schedule-tabela-ovoj">
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
                        $jeExamOnly = !empty($entry["exam_only"]);
                        $imaOcenjevanje = !empty($entry["ocenjevanja"]);
                        $dnevniRazlog = null;
                        $enkratna = null;

                        if (!$jeExtra && !$jeExamOnly) {
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
                        if ($imaOcenjevanje && !$odpade) {
                            $cellClass .= " schedule-has-exam";
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
                                <?= e($entry["priimek"] . " " . $entry["ime"]) ?><?= !empty($entry["ucilnica"]) ? " · " . e($entry["ucilnica"]) : "" ?>
                            </small>

                            <?php if ($user["vloga"] !== "dijak" && $entry["oddelek"]): ?>
                                <br>
                                <small class="text-muted">
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

                            <?php if ($imaOcenjevanje): ?>
                                <?php foreach ($entry["ocenjevanja"] as $oc): ?>
                                    <div class="schedule-exam-banner">
                                        <div class="schedule-exam-banner-top">
                                            <span class="schedule-exam-badge">Pisno ocenjevanje</span>
                                            <?php if ($user["vloga"] === "admin" || ($user["vloga"] === "ucitelj" && (int) $oc["ucitelj_id"] === (int) $user["id_uporabnik"])): ?>
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="action" value="izbrisi_ocenjevanje">
                                                    <input type="hidden" name="id" value="<?= (int) $oc["id_ocenjevanje"] ?>">
                                                    <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                                    <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                                    <button type="submit" class="schedule-exam-del-btn" title="Odstrani pisno ocenjevanje" onclick="return confirm('Odstranim pisno ocenjevanje z urnika?')">&times;</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <strong class="schedule-exam-title"><?= e($oc["naslov"]) ?></strong>
                                        <?php if (!empty($oc["opis"])): ?>
                                            <small class="schedule-exam-desc"><?= e($oc["opis"]) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php
                            $lahkoDodeliOcenjevanje = !$odpade
                                && !empty($entry["oddelek_id"])
                                && !empty($entry["predmet_id"])
                                && mb_strtolower(trim($entry["naziv"] ?? ""), "UTF-8") !== "razredna ura"
                                && (
                                    $user["vloga"] === "admin"
                                    || ($user["vloga"] === "ucitelj" && (int) ($entry["ucitelj_id"] ?? 0) === (int) $user["id_uporabnik"])
                                );
                            ?>
                            <?php if ($lahkoDodeliOcenjevanje): ?>
                                <details class="schedule-exam-quick">
                                    <summary><?= $imaOcenjevanje ? "Uredi ocenjevanje" : "+ Pisno ocenjevanje" ?></summary>
                                    <form method="post" class="schedule-exam-quick-form">
                                        <input type="hidden" name="action" value="dodaj_ocenjevanje">
                                        <input type="hidden" name="datum" value="<?= e($datumKljuc) ?>">
                                        <input type="hidden" name="ura" value="<?= (int) $ura ?>">
                                        <input type="hidden" name="predmet_id" value="<?= (int) $entry["predmet_id"] ?>">
                                        <input type="hidden" name="oddelek_id" value="<?= (int) $entry["oddelek_id"] ?>">
                                        <input type="hidden" name="ucitelj_id" value="<?= (int) ($entry["ucitelj_id"] ?? $user["id_uporabnik"]) ?>">
                                        <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                        <input type="text" name="naslov" maxlength="150" placeholder="Naslov (npr. 1. pisno ocenjevanje)" value="<?= e($entry["ocenjevanja"][0]["naslov"] ?? "") ?>" required>
                                        <input type="text" name="opis" maxlength="255" placeholder="Snov / opomba (neobvezno)" value="<?= e($entry["ocenjevanja"][0]["opis"] ?? "") ?>">
                                        <button type="submit" class="btn small blue">Shrani ocenjevanje</button>
                                    </form>
                                </details>
                            <?php endif; ?>

                            <?php
                            $lahkoUrejaUro = $user["vloga"] === "admin"
                                || ($user["vloga"] === "ucitelj" && (int) ($entry["ucitelj_id"] ?? 0) === (int) $user["id_uporabnik"]);
                            ?>
                            <?php if ($lahkoUrejaUro && $jeExtra): ?>

                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="izbrisi_spremembo">
                                    <input type="hidden" name="id" value="<?= (int) $entry["id_sprememba"] ?>">
                                    <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                    <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                    <button class="gumb-odstrani" onclick="return confirm('Odstranim to uro?')">Odstrani</button>
                                </form>

                            <?php elseif ($lahkoUrejaUro && !$jeExamOnly): ?>

                                <details>

                                    <summary>Uredi uro / učilnico</summary>

                                    <?php if ($enkratna !== null): ?>

                                        <form method="post">
                                            <input type="hidden" name="action" value="izbrisi_spremembo">
                                            <input type="hidden" name="id" value="<?= (int) $enkratna["id"] ?>">
                                            <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                            <button class="btn small">Vrni uro (<?= e($datumiTedna[$day]->format("j. n.")) ?>)</button>
                                        </form>

                                    <?php else: ?>

                                        <form method="post">
                                            <input type="hidden" name="action" value="odpade_ura">
                                            <input type="hidden" name="urnik_id" value="<?= (int) $entry["id_urnik"] ?>">
                                            <input type="hidden" name="datum" value="<?= e($datumKljuc) ?>">
                                            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                            <input type="text" name="razlog" maxlength="200" placeholder="Razlog (npr. odsotnost učitelja)">
                                            <button class="btn small">Odpadla ura samo <?= e($datumiTedna[$day]->format("j. n.")) ?></button>
                                        </form>

                                    <?php endif; ?>

                                    <form method="post">

                                        <input type="hidden" name="action" value="uredi">
                                        <input type="hidden" name="id" value="<?= $entry["id_urnik"] ?>">
                                        <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                        <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">

                                        <?php
                                        $predmetiZaUrejanje = $predmeti;
                                        if ($user["vloga"] === "ucitelj") {
                                            $imaPredmet = false;
                                            foreach ($predmetiZaUrejanje as $prCheck) {
                                                if ((int) $prCheck["id_predmet"] === (int) $entry["predmet_id"]) {
                                                    $imaPredmet = true;
                                                    break;
                                                }
                                            }
                                            if (!$imaPredmet) {
                                                $predmetiZaUrejanje[] = [
                                                    "id_predmet" => $entry["predmet_id"],
                                                    "naziv" => $entry["naziv"]
                                                ];
                                            }
                                        }
                                        scheduleFields(
                                            $entry,
                                            $predmetiZaUrejanje,
                                            $ucitelji,
                                            $days
                                        );
                                        ?>

                                        <button class="btn small">Shrani (ura / učilnica)</button>

                                    </form>

                                    <form method="post">

                                        <input type="hidden" name="action" value="izbrisi">
                                        <input type="hidden" name="id" value="<?= $entry["id_urnik"] ?>">
                                        <input type="hidden" name="nazaj_teden" value="<?= e($tedenVrednost) ?>">
                                        <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">

                                        <button
                                            class="gumb-odstrani"
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
</div>

<?php if ($user["vloga"] !== "dijak"): ?>
<script>
let allUrnikTabsShown = false;

function openUrnikTab(tabId, btnEl) {
    allUrnikTabsShown = false;
    const toggleBtn = document.getElementById('btnToggleAllUrnikTabs');
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
}

function toggleAllUrnikTabs() {
    allUrnikTabsShown = !allUrnikTabsShown;
    const toggleBtn = document.getElementById('btnToggleAllUrnikTabs');

    if (allUrnikTabsShown) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('active'));
        document.querySelectorAll('.admin-tab-btn').forEach(btn => btn.classList.add('active'));
        if (toggleBtn) toggleBtn.textContent = 'Prikaži po zavihkih';
    } else {
        openUrnikTab('urnik-tabela', document.querySelector('.admin-tab-btn[data-tab="urnik-tabela"]'));
    }
}
</script>
<?php endif; ?>


<?php pageEnd(); ?>
