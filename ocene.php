<?php

require_once "funkcije.php";

requireLogin();

$user = getUser();

$vrsteOcenjevanjaSeznam = [
    "Pisno ocenjevanje",
    "Ustno ocenjevanje",
    "Praktične vaje / projekt",
    "Domača naloga"
];

// ==============================
// AKCIJE UČITELJA IN ADMINA
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        // 1. UČITELJ DODAJA POSAMEZNO OCENO DIJAKU
        if ($action === "dodaj_oceno" && $user["vloga"] === "ucitelj") {

            $dijak_id       = (int) ($_POST["dijak_id"] ?? 0);
            $predmet_id     = (int) ($_POST["predmet_id"] ?? 0);
            $ocena          = (int) ($_POST["ocena"] ?? 0);
            $vrsta          = trim($_POST["vrsta_ocenjevanja"] ?? "Pisno ocenjevanje");
            $naslov         = trim($_POST["naslov_ocene"] ?? "");
            $opis           = trim($_POST["opis"] ?? "");
            $datum          = $_POST["datum_ocenitve"] ?? date("Y-m-d");
            $ocenjevanje_id = !empty($_POST["ocenjevanje_id"]) ? (int) $_POST["ocenjevanje_id"] : null;

            if (!in_array($vrsta, $vrsteOcenjevanjaSeznam, true)) {
                $vrsta = "Pisno ocenjevanje";
            }

            $stmt = $conn->prepare("
                SELECT 1 FROM Ucitelj_Predmet WHERE ucitelj_id = ? AND predmet_id = ?
                UNION
                SELECT 1 FROM Urnik WHERE ucitelj_id = ? AND predmet_id = ?
            ");
            $stmt->execute([$user["id_uporabnik"], $predmet_id, $user["id_uporabnik"], $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }

            $stmt = $conn->prepare("
                SELECT 1 FROM Dijak_Predmet WHERE dijak_id = ? AND predmet_id = ?
            ");
            $stmt->execute([$dijak_id, $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Dijak ne obiskuje tega predmeta.");
            }

            if ($ocena < 1 || $ocena > 5) {
                throw new RuntimeException("Ocena mora biti med 1 in 5.");
            }

            if ($naslov === "") {
                throw new RuntimeException("Vnesite naslov ocenjevanja.");
            }

            $stmt = $conn->prepare("
                INSERT INTO Ocena
                (dijak_id, predmet_id, ucitelj_id, ocena, vrsta_ocenjevanja, naslov_ocene, opis, ocenjevanje_id, datum_ocenitve)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $dijak_id,
                $predmet_id,
                $user["id_uporabnik"],
                $ocena,
                $vrsta,
                $naslov,
                $opis,
                $ocenjevanje_id,
                $datum
            ]);

            flash("Ocena za ocenjevanje »" . $naslov . "« je bila uspešno dodana.");

            $stmtOdd = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
            $stmtOdd->execute([$dijak_id]);
            $dOdd = $stmtOdd->fetchColumn();

            $redirectUrl = "ocene.php";
            if ($dOdd) {
                $redirectUrl .= "?oddelek=" . (int) $dOdd . "&dijak=" . (int) $dijak_id;
            } else {
                $redirectUrl .= "?dijak=" . (int) $dijak_id;
            }
            redirect($redirectUrl);
        }

        // 2. UČITELJ SKUPINSKO OCENI RAZRED ZA DOLOČENO OCENJEVANJE
        elseif ($action === "dodaj_ocene_skupinsko" && $user["vloga"] === "ucitelj") {

            $oddelek_id     = (int) ($_POST["oddelek_id"] ?? 0);
            $predmet_id     = (int) ($_POST["predmet_id"] ?? 0);
            $vrsta          = trim($_POST["vrsta_ocenjevanja"] ?? "Pisno ocenjevanje");
            $naslov         = trim($_POST["naslov_ocene"] ?? "");
            $datum          = $_POST["datum_ocenitve"] ?? date("Y-m-d");
            $ocenjevanje_id = !empty($_POST["ocenjevanje_id"]) ? (int) $_POST["ocenjevanje_id"] : null;
            $ocenePolje     = $_POST["ocene"] ?? [];
            $opisiPolje     = $_POST["opisi"] ?? [];

            if (!in_array($vrsta, $vrsteOcenjevanjaSeznam, true)) {
                $vrsta = "Pisno ocenjevanje";
            }

            if ($oddelek_id <= 0 || $predmet_id <= 0 || $naslov === "") {
                throw new RuntimeException("Izberite razred, predmet in vnesite naslov ocenjevanja.");
            }

            $stmt = $conn->prepare("
                SELECT 1 FROM Ucitelj_Predmet WHERE ucitelj_id = ? AND predmet_id = ?
                UNION
                SELECT 1 FROM Urnik WHERE ucitelj_id = ? AND predmet_id = ?
            ");
            $stmt->execute([$user["id_uporabnik"], $predmet_id, $user["id_uporabnik"], $predmet_id]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Tega predmeta ne poučujete.");
            }

            $stmtIns = $conn->prepare("
                INSERT INTO Ocena
                (dijak_id, predmet_id, ucitelj_id, ocena, vrsta_ocenjevanja, naslov_ocene, opis, ocenjevanje_id, datum_ocenitve)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stVpisov = 0;
            foreach ($ocenePolje as $dijakIdKey => $ocVal) {
                $dId = (int) $dijakIdKey;
                $ocInt = (int) $ocVal;
                if ($dId <= 0 || $ocInt < 1 || $ocInt > 5) {
                    continue;
                }
                $komentarDijak = trim($opisiPolje[$dijakIdKey] ?? "");
                $stmtIns->execute([
                    $dId,
                    $predmet_id,
                    $user["id_uporabnik"],
                    $ocInt,
                    $vrsta,
                    $naslov,
                    $komentarDijak,
                    $ocenjevanje_id,
                    $datum
                ]);
                $stVpisov++;
            }

            if ($stVpisov === 0) {
                throw new RuntimeException("Niste izbrali nobene ocene za vnos.");
            }

            flash("Uspešno ste vpisali " . $stVpisov . " ocen za ocenjevanje »" . $naslov . "«.");
            redirect("ocene.php?oddelek=" . $oddelek_id);
        }

        // 3. IZBRIŠI OCENO (UČITELJ SVOJO ALI ADMIN KATEROKOLI)
        elseif ($action === "izbrisi_oceno" && in_array($user["vloga"], ["ucitelj", "admin"], true)) {

            $id_ocena = (int) ($_POST["id_ocena"] ?? 0);
            if ($user["vloga"] === "ucitelj") {
                $stmtDel = $conn->prepare("DELETE FROM Ocena WHERE id_ocena = ? AND ucitelj_id = ?");
                $stmtDel->execute([$id_ocena, $user["id_uporabnik"]]);
            } else {
                $stmtDel = $conn->prepare("DELETE FROM Ocena WHERE id_ocena = ?");
                $stmtDel->execute([$id_ocena]);
            }

            flash("Ocena je bila odstranjena.");
            $nazajOddelek = (int) ($_POST["nazaj_oddelek"] ?? 0);
            $nazajDijak   = (int) ($_POST["nazaj_dijak"] ?? 0);
            $redirectUrl = "ocene.php";
            if ($nazajOddelek > 0 && $nazajDijak > 0) {
                $redirectUrl .= "?oddelek=" . $nazajOddelek . "&dijak=" . $nazajDijak;
            } elseif ($nazajOddelek > 0) {
                $redirectUrl .= "?oddelek=" . $nazajOddelek;
            }
            redirect($redirectUrl);
        }

        // 4. UČITELJ OCENJUJE ODDANO NALOGO
        elseif ($action === "oceni_oddajo" && $user["vloga"] === "ucitelj") {

            $id_oddana = (int) $_POST["id_oddana"];
            $ocena     = (int) $_POST["ocena"];
            $komentar  = trim($_POST["komentar_ucitelja"]);

            $stmt = $conn->prepare("
                SELECT o.id_oddana
                FROM Oddana_Naloga o
                JOIN Naloga n ON n.id_naloga = o.naloga_id
                WHERE o.id_oddana = ? AND n.ucitelj_id = ?
            ");
            $stmt->execute([$id_oddana, $user["id_uporabnik"]]);

            if (!$stmt->fetch()) {
                throw new RuntimeException("Ta oddaja ne pripada vaši nalogi.");
            }

            $stmt = $conn->prepare("
                UPDATE Oddana_Naloga
                SET ocena = ?, komentar_ucitelja = ?, ocenjeno_ob = NOW()
                WHERE id_oddana = ?
            ");
            $stmt->execute([$ocena, $komentar, $id_oddana]);

            flash("Oddaja je bila ocenjena.");
            redirect("ocene.php");
        }

    } catch (Throwable $e) {
        flash("Napaka: " . userErrorMessage($e));
        redirect("ocene.php");
    }
}


// ==============================
// PRIDOBIVANJE PODATKOV
// ==============================

if ($user["vloga"] === "dijak") {

    $dijakId = (int) $user["id_uporabnik"];
    $dijakOddelekId = (int) ($user["oddelek_id"] ?? 0);

    // Vsi vpisani predmeti dijaka z dejanskimi profesorji njegovega oddelka (brez Razredne ure)
    $stmtPredmeti = $conn->prepare("
        SELECT p.id_predmet, p.naziv,
               COALESCE(
                   (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.ime, ' ', u.priimek) ORDER BY u.priimek SEPARATOR ', ')
                    FROM Urnik urn
                    JOIN Uporabnik u ON u.id_uporabnik = urn.ucitelj_id
                    WHERE urn.oddelek_id = ? AND urn.predmet_id = p.id_predmet),
                   (SELECT CONCAT(u.ime, ' ', u.priimek)
                    FROM Ocena oc
                    JOIN Uporabnik u ON u.id_uporabnik = oc.ucitelj_id
                    WHERE oc.dijak_id = ? AND oc.predmet_id = p.id_predmet LIMIT 1),
                   '—'
               ) AS ucitelji
        FROM Predmet p
        JOIN Dijak_Predmet dp ON dp.predmet_id = p.id_predmet
        WHERE dp.dijak_id = ? AND LOWER(p.naziv) <> 'razredna ura'
        GROUP BY p.id_predmet
        ORDER BY p.naziv
    ");
    $stmtPredmeti->execute([$dijakOddelekId, $dijakId, $dijakId]);
    $mojiPredmeti = $stmtPredmeti->fetchAll();

    // Vse ocene dijaka iz tabele Ocena (brez Razredne ure)
    $stmt = $conn->prepare("
        SELECT o.*, p.naziv AS predmet,
               u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
        FROM Ocena o
        JOIN Predmet p ON p.id_predmet = o.predmet_id
        JOIN Uporabnik u ON u.id_uporabnik = o.ucitelj_id
        WHERE o.dijak_id = ? AND LOWER(p.naziv) <> 'razredna ura'
        ORDER BY o.datum_ocenitve DESC, o.id_ocena DESC
    ");
    $stmt->execute([$dijakId]);
    $oceneRedne = $stmt->fetchAll();

    // Ocenjene oddane domače naloge dijaka
    $stmtOddaneOcene = $conn->prepare("
        SELECT od.id_oddana, od.ocena, od.komentar_ucitelja, od.ocenjeno_ob, od.oddano_ob,
               n.naslov AS naloga_naslov, n.predmet_id,
               p.naziv AS predmet,
               u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
        FROM Oddana_Naloga od
        JOIN Naloga n ON n.id_naloga = od.naloga_id
        JOIN Predmet p ON p.id_predmet = n.predmet_id
        LEFT JOIN Uporabnik u ON u.id_uporabnik = n.ucitelj_id
        WHERE od.dijak_id = ? AND od.ocena IS NOT NULL
        ORDER BY COALESCE(od.ocenjeno_ob, od.oddano_ob) DESC
    ");
    $stmtOddaneOcene->execute([$dijakId]);
    $oceneNalog = $stmtOddaneOcene->fetchAll();

    // Združi vse ocene za pregled
    $ocene = [];
    foreach ($oceneRedne as $or) {
        $ocene[] = [
            "id_ocena"          => (int) $or["id_ocena"],
            "predmet_id"        => (int) $or["predmet_id"],
            "predmet"           => $or["predmet"],
            "ocena"             => (int) $or["ocena"],
            "vrsta_ocenjevanja" => $or["vrsta_ocenjevanja"] ?? "Pisno ocenjevanje",
            "naslov_ocene"      => $or["naslov_ocene"],
            "opis"              => $or["opis"] ?? "",
            "datum_ocenitve"    => $or["datum_ocenitve"],
            "ucitelj_ime"       => $or["ucitelj_ime"],
            "ucitelj_priimek"   => $or["ucitelj_priimek"],
            "ocenjevanje_id"    => $or["ocenjevanje_id"] ?? null
        ];
    }
    foreach ($oceneNalog as $on) {
        $datumNal = !empty($on["ocenjeno_ob"]) ? substr($on["ocenjeno_ob"], 0, 10) : substr($on["oddano_ob"], 0, 10);
        $ocene[] = [
            "id_ocena"          => 0,
            "predmet_id"        => (int) $on["predmet_id"],
            "predmet"           => $on["predmet"],
            "ocena"             => (int) $on["ocena"],
            "vrsta_ocenjevanja" => "Domača naloga",
            "naslov_ocene"      => "Naloga: " . $on["naloga_naslov"],
            "opis"              => $on["komentar_ucitelja"] ?? "",
            "datum_ocenitve"    => $datumNal,
            "ucitelj_ime"       => $on["ucitelj_ime"] ?? "",
            "ucitelj_priimek"   => $on["ucitelj_priimek"] ?? "",
            "ocenjevanje_id"    => null
        ];
    }

    usort($ocene, function ($a, $b) {
        return strcmp($b["datum_ocenitve"], $a["datum_ocenitve"]);
    });

    // Razpisana pisna ocenjevanja za oddelek dijaka
    $pisnaOcenjevanjaDijaka = [];
    if ($dijakOddelekId > 0) {
        $stmtPo = $conn->prepare("
            SELECT po.*, p.naziv AS predmet_naziv,
                   u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
            FROM Pisno_Ocenjevanje po
            JOIN Predmet p ON p.id_predmet = po.predmet_id
            JOIN Uporabnik u ON u.id_uporabnik = po.ucitelj_id
            WHERE po.oddelek_id = ?
            ORDER BY po.datum DESC, po.ura ASC
        ");
        $stmtPo->execute([$dijakOddelekId]);
        $pisnaOcenjevanjaDijaka = $stmtPo->fetchAll();
    }

    // Združi ocene in pisna ocenjevanja po predmetih
    $ocenePoPredmetih = [];
    $pisnaPoPredmetih = [];
    foreach ($mojiPredmeti as $pr) {
        $ocenePoPredmetih[(int) $pr["id_predmet"]] = [];
        $pisnaPoPredmetih[(int) $pr["id_predmet"]] = [];
    }
    foreach ($ocene as $ocItem) {
        $pid = (int) $ocItem["predmet_id"];
        if (!isset($ocenePoPredmetih[$pid])) {
            $ocenePoPredmetih[$pid] = [];
        }
        $ocenePoPredmetih[$pid][] = $ocItem;
    }
    foreach ($pisnaOcenjevanjaDijaka as $poItem) {
        $pid = (int) $poItem["predmet_id"];
        if (!isset($pisnaPoPredmetih[$pid])) {
            $pisnaPoPredmetih[$pid] = [];
        }
        $pisnaPoPredmetih[$pid][] = $poItem;
    }

    $stOcenSkupaj = count($ocene);
    $skupnoPovprecje = $stOcenSkupaj > 0
        ? round(array_sum(array_column($ocene, "ocena")) / $stOcenSkupaj, 2)
        : null;
    $stOcenjenihPredmetov = count(array_filter($ocenePoPredmetih, fn($arr) => count($arr) > 0));

} else {

    // UČITELJ in ADMIN – hierarhični pregled (Oddelek -> Dijak)
    $oddelki = $conn->query("
        SELECT o.*, COUNT(DISTINCT u.id_uporabnik) AS st_dijakov
        FROM Oddelek o
        LEFT JOIN Uporabnik u ON u.oddelek_id = o.id_oddelek AND u.vloga = 'dijak'
        GROUP BY o.id_oddelek
        ORDER BY o.letnik, o.ime
    ")->fetchAll();

    $izbranOddelek = isset($_GET["oddelek"]) && $_GET["oddelek"] !== "" ? (int) $_GET["oddelek"] : null;
    $izbranDijak   = isset($_GET["dijak"]) && $_GET["dijak"] !== "" ? (int) $_GET["dijak"] : null;

    if ($izbranDijak && !$izbranOddelek) {
        $stmtOdd = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
        $stmtOdd->execute([$izbranDijak]);
        $foundOdd = $stmtOdd->fetchColumn();
        if ($foundOdd) {
            $izbranOddelek = (int) $foundOdd;
        }
    }

    $oddelekInfo      = null;
    $dijakiOddelka    = [];
    $dijakStatsMap    = [];
    $dijakPredmetiMap = [];

    if ($izbranOddelek) {
        $stmt = $conn->prepare("SELECT * FROM Oddelek WHERE id_oddelek = ?");
        $stmt->execute([$izbranOddelek]);
        $oddelekInfo = $stmt->fetch();

        $stmt = $conn->prepare("
            SELECT u.id_uporabnik, u.ime, u.priimek, u.e_posta, o.ime AS oddelek_ime
            FROM Uporabnik u
            JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
            WHERE u.vloga = 'dijak' AND u.oddelek_id = ?
            ORDER BY u.priimek, u.ime
        ");
        $stmt->execute([$izbranOddelek]);
        $dijakiOddelka = $stmt->fetchAll();

        if ($user["vloga"] === "ucitelj") {
            $stmt = $conn->prepare("
                SELECT o.dijak_id, COUNT(o.id_ocena) AS st_ocen, ROUND(AVG(o.ocena), 2) AS povprecje
                FROM Ocena o
                JOIN Uporabnik u ON u.id_uporabnik = o.dijak_id
                WHERE u.oddelek_id = ? AND o.ucitelj_id = ?
                GROUP BY o.dijak_id
            ");
            $stmt->execute([$izbranOddelek, $user["id_uporabnik"]]);
        } else {
            $stmt = $conn->prepare("
                SELECT o.dijak_id, COUNT(o.id_ocena) AS st_ocen, ROUND(AVG(o.ocena), 2) AS povprecje
                FROM Ocena o
                JOIN Uporabnik u ON u.id_uporabnik = o.dijak_id
                WHERE u.oddelek_id = ?
                GROUP BY o.dijak_id
            ");
            $stmt->execute([$izbranOddelek]);
        }

        foreach ($stmt->fetchAll() as $sr) {
            $dijakStatsMap[$sr["dijak_id"]] = $sr;
        }
    }

    $izbraniDijakPodatki = null;
    $oceneDijaka         = [];
    $povprecjeDijaka     = null;

    if ($izbranDijak) {
        $stmt = $conn->prepare("
            SELECT u.*, o.ime AS oddelek_ime, o.program, o.letnik
            FROM Uporabnik u
            LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
            WHERE u.id_uporabnik = ?
        ");
        $stmt->execute([$izbranDijak]);
        $izbraniDijakPodatki = $stmt->fetch();

        if ($izbraniDijakPodatki) {
            if ($user["vloga"] === "ucitelj") {
                $stmt = $conn->prepare("
                    SELECT o.*, p.naziv AS predmet
                    FROM Ocena o
                    JOIN Predmet p ON p.id_predmet = o.predmet_id
                    WHERE o.dijak_id = ? AND o.ucitelj_id = ?
                    ORDER BY o.datum_ocenitve DESC, o.id_ocena DESC
                ");
                $stmt->execute([$izbranDijak, $user["id_uporabnik"]]);
            } else {
                $stmt = $conn->prepare("
                    SELECT o.*, p.naziv AS predmet,
                           u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
                    FROM Ocena o
                    JOIN Predmet p ON p.id_predmet = o.predmet_id
                    JOIN Uporabnik u ON u.id_uporabnik = o.ucitelj_id
                    WHERE o.dijak_id = ?
                    ORDER BY o.datum_ocenitve DESC, o.id_ocena DESC
                ");
                $stmt->execute([$izbranDijak]);
            }
            $oceneDijaka = $stmt->fetchAll();

            if (!empty($oceneDijaka)) {
                $povprecjeDijaka = round(array_sum(array_column($oceneDijaka, "ocena")) / count($oceneDijaka), 2);
            }
        }
    }

    // Podatki za učiteljev obrazec ocenjevanja
    $predmeti          = [];
    $dijakiZaDodajanje = [];
    $uciteljPisnaOcenjevanja = [];

    if ($user["vloga"] === "ucitelj") {
        $stmt = $conn->prepare("
            SELECT DISTINCT p.*
            FROM Predmet p
            WHERE LOWER(p.naziv) <> 'razredna ura'
              AND p.id_predmet IN (
                SELECT predmet_id FROM Ucitelj_Predmet WHERE ucitelj_id = ?
                UNION
                SELECT predmet_id FROM Urnik WHERE ucitelj_id = ?
            )
            ORDER BY p.naziv
        ");
        $stmt->execute([$user["id_uporabnik"], $user["id_uporabnik"]]);
        $predmeti = $stmt->fetchAll();
        $predmetIds = array_column($predmeti, "id_predmet");

        $stmtPoUcitelj = $conn->prepare("
            SELECT po.*, p.naziv AS predmet_naziv, o.ime AS oddelek_ime
            FROM Pisno_Ocenjevanje po
            JOIN Predmet p ON p.id_predmet = po.predmet_id
            JOIN Oddelek o ON o.id_oddelek = po.oddelek_id
            WHERE po.ucitelj_id = ?
            ORDER BY po.datum DESC
        ");
        $stmtPoUcitelj->execute([$user["id_uporabnik"]]);
        $uciteljPisnaOcenjevanja = $stmtPoUcitelj->fetchAll();

        if ($predmetIds) {
            $placeholders = implode(",", array_fill(0, count($predmetIds), "?"));
            $stmt = $conn->prepare("
                SELECT DISTINCT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id, o.ime AS oddelek_ime
                FROM Uporabnik u
                JOIN Dijak_Predmet dp ON dp.dijak_id = u.id_uporabnik
                LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                WHERE dp.predmet_id IN ($placeholders)
                ORDER BY o.letnik, o.ime, u.priimek, u.ime
            ");
            $stmt->execute($predmetIds);
            $dijakiZaDodajanje = $stmt->fetchAll();

            if ($izbranOddelek) {
                $stmt = $conn->prepare("
                    SELECT dp.dijak_id, p.naziv
                    FROM Dijak_Predmet dp
                    JOIN Predmet p ON p.id_predmet = dp.predmet_id
                    JOIN Uporabnik u ON u.id_uporabnik = dp.dijak_id
                    WHERE u.oddelek_id = ? AND dp.predmet_id IN ($placeholders)
                    ORDER BY p.naziv
                ");
                $stmt->execute(array_merge([$izbranOddelek], $predmetIds));
                foreach ($stmt->fetchAll() as $r) {
                    $dijakPredmetiMap[$r["dijak_id"]][] = $r["naziv"];
                }
            }
        }
    }
}

function ocenaOpisBeseda(int $oc): string {
    return [
        5 => "Odlično (5)",
        4 => "Prav dobro (4)",
        3 => "Dobro (3)",
        2 => "Zadostno (2)",
        1 => "Nezadostno (1)"
    ][$oc] ?? (string) $oc;
}

function ocenaBadgeClass(int $oc): string {
    if ($oc >= 4) {
        return "badge-ok";
    }
    if ($oc === 3) {
        return "badge-info";
    }
    if ($oc === 2) {
        return "badge-warn";
    }
    return "badge-danger";
}

pageStart("Ocene in ocenjevanja");
?>

<?php if ($user["vloga"] === "dijak"): ?>

    <h1 class="m-0 mb-1">Vpogled ocen in ocenjevanj znanja</h1>
    <p class="panel-subtitle mb-3">
        Tabelarični pregled vseh prejetih ocen pri pisnih in ustnih ocenjevanjih ter povprečij po predmetih.
    </p>

    <!-- STATISTIKA DIJAKA -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $skupnoPovprecje !== null ? number_format($skupnoPovprecje, 2) : "—" ?></div>
            <div class="stat-label">Skupno povprečje</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stOcenSkupaj ?></div>
            <div class="stat-label">Skupno število ocen</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stOcenjenihPredmetov ?> / <?= count($mojiPredmeti) ?></div>
            <div class="stat-label">Ocenjeni predmeti</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= count($pisnaOcenjevanjaDijaka) ?></div>
            <div class="stat-label">Pisna ocenjevanja v razredu</div>
        </div>
    </div>

    <!-- REDOVALNICA PO PREDMETIH -->
    <section class="panel full mb-4">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Zbirni pregled po predmetih</span>
                <h2 class="m-0 mt-1">Redovalnica po predmetih</h2>
                <p class="off-hint m-0 mt-1">Pregled vseh vpisanih predmetov, prejetih ocen in povprečne ocene pri posameznem predmetu.</p>
            </div>
        </div>

        <div class="tabela-ovoj">
            <table>
                <thead>
                    <tr>
                        <th>Predmet</th>
                        <th>Profesor</th>
                        <th>Prejete ocene (z miško podržite nad oceno za podrobnosti)</th>
                        <th class="text-center">Št. ocen</th>
                        <th class="text-center">Povprečje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mojiPredmeti)): ?>
                        <tr>
                            <td colspan="5" class="text-muted">Trenutno nimate vpisanih predmetov.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mojiPredmeti as $pr): ?>
                            <?php
                            $pid = (int) $pr["id_predmet"];
                            $predOcene = $ocenePoPredmetih[$pid] ?? [];
                            $imaOcene = count($predOcene) > 0;
                            $povprPredmet = $imaOcene
                                ? round(array_sum(array_column($predOcene, "ocena")) / count($predOcene), 2)
                                : null;
                            ?>
                            <tr>
                                <td><strong><?= e($pr["naziv"]) ?></strong></td>
                                <td class="text-muted"><?= e($pr["ucitelji"] ?: "—") ?></td>
                                <td>
                                    <?php if (!$imaOcene): ?>
                                        <span class="text-muted text-xs">Brez ocen</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach (array_reverse($predOcene) as $ocChip): ?>
                                                <?php
                                                $dtChip = DateTime::createFromFormat("Y-m-d", $ocChip["datum_ocenitve"]);
                                                $dChipStr = $dtChip ? $dtChip->format("j. n. Y") : $ocChip["datum_ocenitve"];
                                                $tooltip = $ocChip["vrsta_ocenjevanja"] . " – " . $ocChip["naslov_ocene"] . " (" . $dChipStr . ")";
                                                if (!empty($ocChip["opis"])) {
                                                    $tooltip .= ": " . $ocChip["opis"];
                                                }
                                                ?>
                                                <span class="<?= ocenaBadgeClass((int) $ocChip["ocena"]) ?> py-1 px-2 font-bold" title="<?= e($tooltip) ?>">
                                                    <?= (int) $ocChip["ocena"] ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <strong><?= count($predOcene) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($povprPredmet !== null): ?>
                                        <span class="<?= ocenaBadgeClass((int) round($povprPredmet)) ?> py-1 px-2 font-bold">
                                            <?= number_format($povprPredmet, 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>

    <div class="d-flex justify-between align-center flex-wrap gap-3 mb-1">
        <h1 class="m-0">Ocene in ocenjevanja znanja</h1>
        <?php if ($user["vloga"] === "ucitelj"): ?>
            <button type="button" class="btn small outline" id="btnToggleAllTeacherOceneTabs" onclick="toggleAllTeacherOceneTabs()">
                Prikaži vse zavihke
            </button>
        <?php endif; ?>
    </div>
    <p class="panel-subtitle mb-3">
        Vpisovanje ocen za pisna in ustna ocenjevanja ter pregled ocen po oddelkih in dijakih.
    </p>

    <?php if ($user["vloga"] === "ucitelj"): ?>
        <?php $activeOceneTab = ($izbranOddelek || $izbranDijak) ? "pregled" : "dodaj"; ?>
        <div class="admin-tab-nav">
            <button type="button" class="admin-tab-btn <?= $activeOceneTab === 'dodaj' ? 'active' : '' ?>" data-tab="dodaj" onclick="openTeacherOceneTab('dodaj', this)">
                Dodeli oceno dijaku
            </button>
            <button type="button" class="admin-tab-btn <?= $activeOceneTab === 'pregled' ? 'active' : '' ?>" data-tab="pregled" onclick="openTeacherOceneTab('pregled', this)">
                Redovalnica po oddelkih <span class="tab-badge"><?= count($oddelki) ?></span>
            </button>
        </div>
    <?php endif; ?>

    <div>

        <?php if ($user["vloga"] === "ucitelj"): ?>

            <!-- OBRAZEC ZA DODAJANJE OCENE POSAMEZNEMU DIJAKU -->
            <div id="tab-dodaj" class="tab-content <?= $activeOceneTab === 'dodaj' ? 'active' : '' ?>">
            <section class="panel full" id="dodaj-oceno">
                <div class="enrollment-header">
                    <div>
                        <span class="badge-info mb-1">Vpis ocene za ocenjevanje</span>
                        <h2 class="m-0 mt-1">Dodeli oceno dijaku</h2>
                        <p class="off-hint m-0 mt-1">Izberite obstoječe pisno ocenjevanje z urnika ali ročno vnesite podatke o ocenjevanju.</p>
                    </div>
                </div>

                <form method="POST" class="off-form">
                    <input type="hidden" name="action" value="dodaj_oceno">

                    <?php if (!empty($uciteljPisnaOcenjevanja)): ?>
                        <div class="mb-3">
                            <label class="off-label" for="izbira_pisnega_ocenjevanja">
                                Poveži z razpisanim pisnim ocenjevanjem z urnika (neobvezno – samodejno izpolni polja)
                            </label>
                            <select name="ocenjevanje_id" id="izbira_pisnega_ocenjevanja" class="w-100" onchange="izpolniIzPisnegaOcenjevanja(this)">
                                <option value="">-- Ročni vnos ocenjevanja (brez povezave) --</option>
                                <?php foreach ($uciteljPisnaOcenjevanja as $po): ?>
                                    <option
                                        value="<?= (int) $po["id_ocenjevanje"] ?>"
                                        data-predmet="<?= (int) $po["predmet_id"] ?>"
                                        data-naslov="<?= e($po["naslov"]) ?>"
                                        data-opis="<?= e($po["opis"] ?? "") ?>"
                                        data-datum="<?= e($po["datum"]) ?>"
                                    >
                                        <?= e(date("j. n. Y", strtotime($po["datum"])) . " · " . $po["oddelek_ime"] . " · " . $po["predmet_naziv"] . " – " . $po["naslov"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="grade-entry-grid-top">
                        <label>
                            Dijak
                            <select name="dijak_id" required>
                                <option value="">-- Izberi dijaka --</option>
                                <?php
                                $curGroup = null;
                                foreach ($dijakiZaDodajanje as $d):
                                    $groupLabel = $d["oddelek_ime"] ? "Oddelek " . $d["oddelek_ime"] : "Brez oddelka";
                                    if ($curGroup !== $groupLabel):
                                        if ($curGroup !== null) {
                                            echo "</optgroup>";
                                        }
                                        $curGroup = $groupLabel;
                                        echo '<optgroup label="' . e($curGroup) . '">';
                                    endif;
                                    $isSelected = ($izbranDijak && (int) $d["id_uporabnik"] === (int) $izbranDijak) ? "selected" : "";
                                ?>
                                    <option value="<?= (int) $d["id_uporabnik"] ?>" <?= $isSelected ?>>
                                        <?= e($d["priimek"] . " " . $d["ime"]) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($curGroup !== null) { echo "</optgroup>"; } ?>
                            </select>
                        </label>

                        <label>
                            Predmet
                            <select name="predmet_id" id="ocena_predmet_id" required>
                                <option value="">-- Izberi predmet --</option>
                                <?php foreach ($predmeti as $p): ?>
                                    <option value="<?= (int) $p["id_predmet"] ?>">
                                        <?= e($p["naziv"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Vrsta ocenjevanja
                            <select name="vrsta_ocenjevanja" id="ocena_vrsta" required>
                                <?php foreach ($vrsteOcenjevanjaSeznam as $vrstaItem): ?>
                                    <option value="<?= e($vrstaItem) ?>"><?= e($vrstaItem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Ocena (1–5)
                            <select name="ocena" required>
                                <option value="">-- Ocena --</option>
                                <option value="5">5 – Odlično</option>
                                <option value="4">4 – Prav dobro</option>
                                <option value="3">3 – Dobro</option>
                                <option value="2">2 – Zadostno</option>
                                <option value="1">1 – Nezadostno</option>
                            </select>
                        </label>

                        <label>
                            Datum ocenjevanja
                            <input type="date" name="datum_ocenitve" id="ocena_datum" value="<?= date("Y-m-d") ?>" required>
                        </label>
                    </div>

                    <div class="grade-entry-grid-bottom mt-3">
                        <label>
                            Naslov ocenjevanja
                            <input type="text" name="naslov_ocene" id="ocena_naslov" placeholder="npr. 1. pisno ocenjevanje znanja" required>
                        </label>

                        <label>
                            Komentar / vsebina ocenjevanja (neobvezno)
                            <input type="text" name="opis" id="ocena_opis" placeholder="npr. Kvadratna funkcija – odlično znanje">
                        </label>

                        <div class="office-self-btn-wrap">
                            <button type="submit" class="btn blue btn-h38 nowrap">+ Dodaj oceno dijaku</button>
                        </div>
                    </div>
                </form>
            </section>
            </div>

            <script>
            var allTeacherOceneTabsShown = false;

            function openTeacherOceneTab(tabId, btnEl) {
                allTeacherOceneTabsShown = false;
                var toggleBtn = document.getElementById('btnToggleAllTeacherOceneTabs');
                if (toggleBtn) toggleBtn.textContent = 'Prikaži vse zavihke';

                document.querySelectorAll('.tab-content').forEach(function(el) {
                    el.classList.remove('active');
                });
                document.querySelectorAll('.admin-tab-btn').forEach(function(btn) {
                    btn.classList.remove('active');
                });

                var target = document.getElementById('tab-' + tabId);
                if (target) target.classList.add('active');
                if (btnEl) btnEl.classList.add('active');
            }

            function toggleAllTeacherOceneTabs() {
                allTeacherOceneTabsShown = !allTeacherOceneTabsShown;
                var toggleBtn = document.getElementById('btnToggleAllTeacherOceneTabs');

                if (allTeacherOceneTabsShown) {
                    document.querySelectorAll('.tab-content').forEach(function(el) { el.classList.add('active'); });
                    document.querySelectorAll('.admin-tab-btn').forEach(function(btn) { btn.classList.add('active'); });
                    if (toggleBtn) toggleBtn.textContent = 'Prikaži po zavihkih';
                } else {
                    openTeacherOceneTab('dodaj', document.querySelector('.admin-tab-btn[data-tab="dodaj"]'));
                }
            }

            function izpolniIzPisnegaOcenjevanja(sel) {
                var opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) return;
                var predEl = document.getElementById('ocena_predmet_id');
                var naslEl = document.getElementById('ocena_naslov');
                var opisEl = document.getElementById('ocena_opis');
                var datEl  = document.getElementById('ocena_datum');
                var vrsEl  = document.getElementById('ocena_vrsta');

                if (predEl && opt.getAttribute('data-predmet')) {
                    predEl.value = opt.getAttribute('data-predmet');
                }
                if (naslEl && opt.getAttribute('data-naslov')) {
                    naslEl.value = opt.getAttribute('data-naslov');
                }
                if (opisEl && opt.getAttribute('data-opis')) {
                    opisEl.value = opt.getAttribute('data-opis');
                }
                if (datEl && opt.getAttribute('data-datum')) {
                    datEl.value = opt.getAttribute('data-datum');
                }
                if (vrsEl) {
                    vrsEl.value = 'Pisno ocenjevanje';
                }
            }
            </script>

        <?php endif; ?>

        <!-- GLAVNI PREGLED OCEN Z DVOSTOPENJSKIM FILTROM (ODDELEK -> DIJAK) -->
        <div id="tab-pregled" class="<?= $user["vloga"] === "ucitelj" ? ("tab-content " . ($activeOceneTab === "pregled" ? "active" : "")) : "" ?>">
        <section class="panel full">
            <div class="enrollment-header">
                <div>
                    <span class="badge-info mb-1">Pregled po oddelkih</span>
                    <h2 class="m-0 mt-1">
                        <?= $user["vloga"] === "ucitelj" ? "Redovalnica in skupinsko ocenjevanje razreda" : "Pregled vseh ocen po razredih" ?>
                    </h2>
                    <p class="off-hint m-0 mt-1">
                        Izberite oddelek za pregled ocen vseh dijakov v razredu ali za skupinsko vpisovanje ocen celotnemu oddelku.
                    </p>
                </div>
            </div>

            <form method="GET" action="ocene.php" class="enrollment-top-bar">
                <div class="enrollment-top-field">
                    <label class="enrollment-label" for="filter_oc_oddelek">1. Izbira oddelka</label>
                    <select id="filter_oc_oddelek" name="oddelek" onchange="this.form.submit()" class="enrollment-select">
                        <option value="">-- Izberite oddelek --</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= (int) $o["id_oddelek"] ?>" <?= ($izbranOddelek === (int) $o["id_oddelek"]) ? "selected" : "" ?>>
                                <?= e($o["ime"]) ?> (<?= e($o["program"]) ?>, <?= (int) $o["letnik"] ?>. letnik)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="enrollment-top-field">
                    <label class="enrollment-label" for="filter_oc_dijak">2. Izbira dijaka</label>
                    <select id="filter_oc_dijak" name="dijak" onchange="this.form.submit()" <?= empty($izbranOddelek) ? "disabled" : "" ?> class="enrollment-select">
                        <?php if (empty($izbranOddelek)): ?>
                            <option value="">-- Najprej izberite oddelek --</option>
                        <?php else: ?>
                            <option value="">-- Vsi dijaki v oddelku (seznam) --</option>
                            <?php foreach ($dijakiOddelka as $do): ?>
                                <option value="<?= (int) $do["id_uporabnik"] ?>" <?= ($izbranDijak === (int) $do["id_uporabnik"]) ? "selected" : "" ?>>
                                    <?= e($do["priimek"] . " " . $do["ime"]) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="enrollment-top-btn-wrap gap-2">
                    <button type="submit" class="btn blue btn-enroll-inline">Prikaži</button>
                    <?php if ($izbranOddelek || $izbranDijak): ?>
                        <a href="ocene.php" class="btn btn-secondary btn-enroll-inline">Ponastavi</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!$izbranOddelek): ?>

                <div class="prazno-stanje">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                    <div class="font-bold text-dark text-md mb-1">Najprej izberite oddelek</div>
                    <div class="text-sm text-muted">
                        Izberite oddelek v zgornjem spustnem seznamu za pregled ocen dijakov ali za skupinsko vpisovanje ocen celotnemu razredu ob pisnem ocenjevanju.
                    </div>
                </div>

            <?php elseif ($izbranOddelek && !$izbranDijak): ?>

                <div class="panel-header-actions mb-2">
                    <h3 class="m-0 text-lg text-primary">
                        Seznam dijakov oddelka <?= e($oddelekInfo["ime"]) ?>
                        <small class="text-muted font-normal text-sm">(<?= e($oddelekInfo["program"]) ?>, <?= (int) $oddelekInfo["letnik"] ?>. letnik)</small>
                    </h3>
                    <span class="badge-ok text-sm"><?= count($dijakiOddelka) ?> dijakov</span>
                </div>

                <?php if ($user["vloga"] === "ucitelj" && !empty($dijakiOddelka)): ?>
                    <details class="mb-4">
                        <summary class="btn small blue">
                            + Skupinsko oceni ta razred (<?= e($oddelekInfo["ime"]) ?>) za pisno ali ustno ocenjevanje
                        </summary>
                        <form method="POST" class="card-banner off-form mt-2">
                            <input type="hidden" name="action" value="dodaj_ocene_skupinsko">
                            <input type="hidden" name="oddelek_id" value="<?= (int) $izbranOddelek ?>">

                            <div class="off-row">
                                <div class="off-grow">
                                    <label>
                                        Predmet
                                        <select name="predmet_id" required>
                                            <option value="">-- Izberi predmet --</option>
                                            <?php foreach ($predmeti as $p): ?>
                                                <option value="<?= (int) $p["id_predmet"] ?>"><?= e($p["naziv"]) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Vrsta ocenjevanja
                                        <select name="vrsta_ocenjevanja" required>
                                            <?php foreach ($vrsteOcenjevanjaSeznam as $vrstaItem): ?>
                                                <option value="<?= e($vrstaItem) ?>"><?= e($vrstaItem) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                                <div class="off-grow">
                                    <label>
                                        Naslov ocenjevanja
                                        <input type="text" name="naslov_ocene" placeholder="npr. 1. pisno ocenjevanje znanja" required>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Datum
                                        <input type="date" name="datum_ocenitve" value="<?= date("Y-m-d") ?>" required>
                                    </label>
                                </div>
                            </div>

                            <div class="tabela-ovoj mt-2">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Dijak</th>
                                            <th>Ocena (pustite prazno, če dijak ni bil ocenjen)</th>
                                            <th>Komentar / opomba</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dijakiOddelka as $idx => $d): ?>
                                            <?php $dId = (int) $d["id_uporabnik"]; ?>
                                            <tr>
                                                <td><?= $idx + 1 ?></td>
                                                <td><strong><?= e($d["priimek"] . " " . $d["ime"]) ?></strong></td>
                                                <td>
                                                    <select name="ocene[<?= $dId ?>]">
                                                        <option value="">— Brez ocene —</option>
                                                        <option value="5">5 – Odlično</option>
                                                        <option value="4">4 – Prav dobro</option>
                                                        <option value="3">3 – Dobro</option>
                                                        <option value="2">2 – Zadostno</option>
                                                        <option value="1">1 – Nezadostno</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" name="opisi[<?= $dId ?>]" placeholder="Opomba k oceni (neobvezno)">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="btn blue mt-3">Shrani vpisane ocene za razred <?= e($oddelekInfo["ime"]) ?></button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if (empty($dijakiOddelka)): ?>
                    <p class="prazno-stanje">V tem oddelku trenutno ni vpisanih dijakov.</p>
                <?php else: ?>
                    <div class="tabela-ovoj"><table>
                        <thead>
                            <tr>
                                <th class="w-40">#</th>
                                <th>Dijak</th>
                                <?php if ($user["vloga"] === "ucitelj"): ?>
                                    <th>Predmeti pri vas</th>
                                    <th>Vaše ocene</th>
                                <?php else: ?>
                                    <th>Št. ocen</th>
                                <?php endif; ?>
                                <th>Povprečje</th>
                                <th class="text-right">Dejanja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dijakiOddelka as $idx => $d):
                                $dId = (int) $d["id_uporabnik"];
                                $stOcen = $dijakStatsMap[$dId]["st_ocen"] ?? 0;
                                $povpr = isset($dijakStatsMap[$dId]["povprecje"]) ? number_format((float) $dijakStatsMap[$dId]["povprecje"], 2) : null;
                                $predmetiDijaka = $dijakPredmetiMap[$dId] ?? [];
                            ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td><strong><?= e($d["priimek"] . " " . $d["ime"]) ?></strong></td>
                                <?php if ($user["vloga"] === "ucitelj"): ?>
                                    <td>
                                        <?php if (!empty($predmetiDijaka)): ?>
                                            <small><?= e(implode(", ", $predmetiDijaka)) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Ni vpisan k vašim predmetom</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $stOcen > 0 ? "<strong>" . $stOcen . "</strong>" : '<span class="text-muted">0</span>' ?></td>
                                <?php else: ?>
                                    <td><?= $stOcen > 0 ? "<strong>" . $stOcen . "</strong>" : '<span class="text-muted">0</span>' ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($povpr !== null): ?>
                                        <span class="badge-ok text-sm font-bold"><?= $povpr ?></span>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">Brez ocen</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right nowrap">
                                    <a href="ocene.php?oddelek=<?= $izbranOddelek ?>&dijak=<?= $dId ?>" class="btn blue small">
                                        Poglej ocene &rarr;
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>

            <?php elseif ($izbranOddelek && $izbranDijak && $izbraniDijakPodatki): ?>

                <div class="mb-2">
                    <a href="ocene.php?oddelek=<?= $izbranOddelek ?>" class="back-link" title="Nazaj na seznam oddelka <?= e($oddelekInfo["ime"] ?? "") ?>" aria-label="Nazaj">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    </a>
                </div>

                <div class="info-banner mb-4">
                    <div>
                        <h3 class="mt-0 mb-1 text-xl text-primary">
                            <?= e($izbraniDijakPodatki["priimek"] . " " . $izbraniDijakPodatki["ime"]) ?>
                        </h3>
                        <p class="m-0 text-muted text-sm">
                            Oddelek: <strong><?= e($izbraniDijakPodatki["oddelek_ime"] ?? "Neznano") ?></strong>
                            <?php if (!empty($izbraniDijakPodatki["program"])): ?>
                                &bull; <?= e($izbraniDijakPodatki["program"]) ?>, <?= (int) $izbraniDijakPodatki["letnik"] ?>. letnik
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="d-flex gap-4 items-center">
                        <div class="text-center">
                            <div class="stat-label">Št. ocen</div>
                            <div class="stat-value text-primary"><?= count($oceneDijaka) ?></div>
                        </div>
                        <div class="text-center">
                            <div class="stat-label">
                                <?= $user["vloga"] === "ucitelj" ? "Vaše povprečje" : "Skupno povprečje" ?>
                            </div>
                            <div class="stat-value <?= $povprecjeDijaka !== null ? "text-primary" : "text-muted" ?>">
                                <?= $povprecjeDijaka !== null ? number_format($povprecjeDijaka, 2) : "—" ?>
                            </div>
                        </div>
                    </div>
                </div>

                <h3 class="my-2 text-md text-primary">
                    <?= $user["vloga"] === "ucitelj" ? "Ocene dijaka pri vaših predmetih" : "Vse ocene dijaka" ?>
                </h3>

                <?php if (empty($oceneDijaka)): ?>
                    <p class="prazno-stanje">
                        Ta dijak še nima vnesenih ocen<?= $user["vloga"] === "ucitelj" ? " pri vaših predmetih" : "" ?>.
                    </p>
                <?php else: ?>
                    <div class="tabela-ovoj"><table>
                        <thead>
                            <tr>
                                <th>Predmet</th>
                                <th>Vrsta</th>
                                <th>Ocena</th>
                                <th>Naslov ocenjevanja</th>
                                <th>Opis / komentar</th>
                                <th>Datum</th>
                                <?php if ($user["vloga"] === "admin"): ?>
                                    <th>Učitelj</th>
                                <?php endif; ?>
                                <th class="text-right">Dejanja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($oceneDijaka as $o): ?>
                                <tr>
                                    <td><strong><?= e($o["predmet"]) ?></strong></td>
                                    <td><span class="badge badge-info"><?= e($o["vrsta_ocenjevanja"] ?? "Pisno ocenjevanje") ?></span></td>
                                    <td>
                                        <span class="<?= ocenaBadgeClass((int) $o["ocena"]) ?> text-sm py-1 px-2">
                                            <strong><?= e(ocenaOpisBeseda((int) $o["ocena"])) ?></strong>
                                        </span>
                                    </td>
                                    <td><strong><?= e($o["naslov_ocene"]) ?></strong></td>
                                    <td><?= e($o["opis"] ?: "—") ?></td>
                                    <td class="nowrap"><?= e($o["datum_ocenitve"]) ?></td>
                                    <?php if ($user["vloga"] === "admin"): ?>
                                        <td><?= e($o["ucitelj_priimek"] . " " . $o["ucitelj_ime"]) ?></td>
                                    <?php endif; ?>
                                    <td class="text-right">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="izbrisi_oceno">
                                            <input type="hidden" name="id_ocena" value="<?= (int) $o["id_ocena"] ?>">
                                            <input type="hidden" name="nazaj_oddelek" value="<?= (int) $izbranOddelek ?>">
                                            <input type="hidden" name="nazaj_dijak" value="<?= (int) $izbranDijak ?>">
                                            <button type="submit" class="gumb-odstrani" onclick="return confirm('Izbrišem to oceno?')">Izbriši</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>

            <?php endif; ?>

        </section>
        </div>

    </div>

<?php endif; ?>

<?php pageEnd(); ?>
