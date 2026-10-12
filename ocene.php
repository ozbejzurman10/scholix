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

            flash("Ocena je bila uspešno dodana.");

            // Usmeri nazaj na pregled ocen tega dijaka
            $stmtOdd = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
            $stmtOdd->execute([$dijak_id]);
            $dOdd = $stmtOdd->fetchColumn();

            $redirectUrl = "ocene.php";
            if ($dOdd) {
                $redirectUrl .= "?oddelek=" . (int)$dOdd . "&dijak=" . (int)$dijak_id;
            } else {
                $redirectUrl .= "?dijak=" . (int)$dijak_id;
            }
            redirect($redirectUrl);
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
            redirect("ocene.php");
        }

    } catch (Throwable $e) {

        flash("Napaka: " . $e->getMessage());
        redirect("ocene.php");
    }
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

// UČITELJ in ADMIN – hierarhični pregled (Oddelek -> Dijak)
else {

    // Seznam vseh oddelkov s številom dijakov
    $oddelki = $conn->query(
        "SELECT o.*, COUNT(DISTINCT u.id_uporabnik) AS st_dijakov
         FROM Oddelek o
         LEFT JOIN Uporabnik u ON u.oddelek_id = o.id_oddelek AND u.vloga = 'dijak'
         GROUP BY o.id_oddelek
         ORDER BY o.letnik, o.ime"
    )->fetchAll();

    // Filtra iz URL-ja
    $izbranOddelek = isset($_GET["oddelek"]) && $_GET["oddelek"] !== "" ? (int) $_GET["oddelek"] : null;
    $izbranDijak   = isset($_GET["dijak"]) && $_GET["dijak"] !== "" ? (int) $_GET["dijak"] : null;

    // Če je izbran dijak brez oddelka v URL-ju, poišči njegov oddelek
    if ($izbranDijak && !$izbranOddelek) {
        $stmtOdd = $conn->prepare("SELECT oddelek_id FROM Uporabnik WHERE id_uporabnik = ?");
        $stmtOdd->execute([$izbranDijak]);
        $foundOdd = $stmtOdd->fetchColumn();
        if ($foundOdd) {
            $izbranOddelek = (int) $foundOdd;
        }
    }

    // Podatki za izbran oddelek
    $oddelekInfo   = null;
    $dijakiOddelka = [];
    $dijakStatsMap = [];
    $dijakPredmetiMap = [];

    if ($izbranOddelek) {
        $stmt = $conn->prepare("SELECT * FROM Oddelek WHERE id_oddelek = ?");
        $stmt->execute([$izbranOddelek]);
        $oddelekInfo = $stmt->fetch();

        // Dijaki v izbranem oddelku
        $stmt = $conn->prepare(
            "SELECT u.id_uporabnik, u.ime, u.priimek, u.e_posta, o.ime AS oddelek_ime
             FROM Uporabnik u
             JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
             WHERE u.vloga = 'dijak' AND u.oddelek_id = ?
             ORDER BY u.priimek, u.ime"
        );
        $stmt->execute([$izbranOddelek]);
        $dijakiOddelka = $stmt->fetchAll();

        // Statistika ocen za dijake v oddelku
        if ($user["vloga"] === "ucitelj") {
            $stmt = $conn->prepare(
                "SELECT o.dijak_id, COUNT(o.id_ocena) AS st_ocen, ROUND(AVG(o.ocena), 2) AS povprecje
                 FROM Ocena o
                 JOIN Uporabnik u ON u.id_uporabnik = o.dijak_id
                 WHERE u.oddelek_id = ? AND o.ucitelj_id = ?
                 GROUP BY o.dijak_id"
            );
            $stmt->execute([$izbranOddelek, $user["id_uporabnik"]]);
        } else {
            // Admin vidi povprečje vseh ocen
            $stmt = $conn->prepare(
                "SELECT o.dijak_id, COUNT(o.id_ocena) AS st_ocen, ROUND(AVG(o.ocena), 2) AS povprecje
                 FROM Ocena o
                 JOIN Uporabnik u ON u.id_uporabnik = o.dijak_id
                 WHERE u.oddelek_id = ?
                 GROUP BY o.dijak_id"
            );
            $stmt->execute([$izbranOddelek]);
        }

        $statsRows = $stmt->fetchAll();
        foreach ($statsRows as $sr) {
            $dijakStatsMap[$sr["dijak_id"]] = $sr;
        }
    }

    // Podatki za izbranega dijaka
    $izbraniDijakPodatki = null;
    $oceneDijaka = [];
    $povprecjeDijaka = null;

    if ($izbranDijak) {
        $stmt = $conn->prepare(
            "SELECT u.*, o.ime AS oddelek_ime, o.program, o.letnik
             FROM Uporabnik u
             LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
             WHERE u.id_uporabnik = ?"
        );
        $stmt->execute([$izbranDijak]);
        $izbraniDijakPodatki = $stmt->fetch();

        if ($izbraniDijakPodatki) {
            if ($user["vloga"] === "ucitelj") {
                $stmt = $conn->prepare(
                    "SELECT o.*, p.naziv AS predmet
                     FROM Ocena o
                     JOIN Predmet p ON p.id_predmet = o.predmet_id
                     WHERE o.dijak_id = ? AND o.ucitelj_id = ?
                     ORDER BY o.datum_ocenitve DESC"
                );
                $stmt->execute([$izbranDijak, $user["id_uporabnik"]]);
            } else {
                $stmt = $conn->prepare(
                    "SELECT o.*, p.naziv AS predmet,
                            u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek
                     FROM Ocena o
                     JOIN Predmet p ON p.id_predmet = o.predmet_id
                     JOIN Uporabnik u ON u.id_uporabnik = o.ucitelj_id
                     WHERE o.dijak_id = ?
                     ORDER BY o.datum_ocenitve DESC"
                );
                $stmt->execute([$izbranDijak]);
            }
            $oceneDijaka = $stmt->fetchAll();

            if (!empty($oceneDijaka)) {
                $povprecjeDijaka = round(array_sum(array_column($oceneDijaka, "ocena")) / count($oceneDijaka), 2);
            }
        }
    }

    // Za učitelja: predmeti učitelja in dijaki za obrazec "Dodaj oceno"
    if ($user["vloga"] === "ucitelj") {
        $stmt = $conn->prepare(
            "SELECT p.* FROM Predmet p
             JOIN Ucitelj_Predmet up ON up.predmet_id = p.id_predmet
             WHERE up.ucitelj_id = ?
             ORDER BY p.naziv"
        );
        $stmt->execute([$user["id_uporabnik"]]);
        $predmeti = $stmt->fetchAll();
        $predmetIds = array_column($predmeti, "id_predmet");

        if ($predmetIds) {
            $placeholders = implode(",", array_fill(0, count($predmetIds), "?"));
            $stmt = $conn->prepare(
                "SELECT DISTINCT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id, o.ime AS oddelek_ime
                 FROM Uporabnik u
                 JOIN Dijak_Predmet dp ON dp.dijak_id = u.id_uporabnik
                 LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                 WHERE dp.predmet_id IN ($placeholders)
                 ORDER BY o.letnik, o.ime, u.priimek, u.ime"
            );
            $stmt->execute($predmetIds);
            $dijakiZaDodajanje = $stmt->fetchAll();

            // Če imamo izbran oddelek, poiščimo kateri predmeti tega učitelja pripadajo dijakom tega oddelka
            if ($izbranOddelek) {
                $stmt = $conn->prepare(
                    "SELECT dp.dijak_id, p.naziv
                     FROM Dijak_Predmet dp
                     JOIN Predmet p ON p.id_predmet = dp.predmet_id
                     JOIN Uporabnik u ON u.id_uporabnik = dp.dijak_id
                     WHERE u.oddelek_id = ? AND dp.predmet_id IN ($placeholders)
                     ORDER BY p.naziv"
                );
                $stmt->execute(array_merge([$izbranOddelek], $predmetIds));
                $dpmRows = $stmt->fetchAll();
                foreach ($dpmRows as $r) {
                    $dijakPredmetiMap[$r["dijak_id"]][] = $r["naziv"];
                }
            }
        } else {
            $dijakiZaDodajanje = [];
        }
    }
}


pageStart("Ocene");

?>

<h1>Ocene</h1>

<div class="admin-grid">

<?php if ($user["vloga"] === "dijak"): ?>

    <!-- PREGLED OCEN ZA DIJAKA -->
    <section class="panel full">
        <h2>Moje ocene</h2>

        <?php if (empty($ocene)): ?>
            <p>Trenutno nimate vnesenih ocen.</p>
        <?php else: ?>

            <?php 
                $avg = round(array_sum(array_column($ocene, "ocena")) / count($ocene), 2);
            ?>
            <div style="background: #f8fbff; border: 1px solid #d0e0f2; border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <strong>Skupno število ocen:</strong> <?= count($ocene) ?>
                </div>
                <div>
                    <strong>Povprečna ocena: </strong>
                    <span class="badge-ok" style="font-size: 14px; font-weight: 700;"><?= number_format($avg, 2) ?></span>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Predmet</th>
                        <th>Ocena</th>
                        <th>Naslov</th>
                        <th>Opis</th>
                        <th>Datum</th>
                        <th>Učitelj</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ocene as $o): ?>
                    <tr>
                        <td><strong><?= e($o["predmet"]) ?></strong></td>
                        <td>
                            <span class="badge-ok" style="font-size: 14px; padding: 3px 10px;">
                                <strong><?= e($o["ocena"]) ?></strong>
                            </span>
                        </td>
                        <td><?= e($o["naslov_ocene"]) ?></td>
                        <td><?= e($o["opis"]) ?></td>
                        <td><?= e($o["datum_ocenitve"]) ?></td>
                        <td><?= e($o["ucitelj_priimek"] . " " . $o["ucitelj_ime"]) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </section>

<?php else: ?>

    <?php if ($user["vloga"] === "ucitelj"): ?>

        <!-- OBRAZEC ZA DODAJANJE OCENE -->
        <section class="panel full" id="dodaj-oceno">
            <h2>Dodaj oceno</h2>

            <form method="POST">
                <input type="hidden" name="action" value="dodaj_oceno">

                <label>
                    Dijak
                    <select name="dijak_id" required>
                        <option value="">Izberi dijaka</option>
                        <?php
                        $curGroup = null;
                        foreach ($dijakiZaDodajanje as $d):
                            $groupLabel = $d["oddelek_ime"] ? "Oddelek " . $d["oddelek_ime"] : "Brez oddelka";
                            if ($curGroup !== $groupLabel):
                                if ($curGroup !== null) echo "</optgroup>";
                                $curGroup = $groupLabel;
                                echo '<optgroup label="' . e($curGroup) . '">';
                            endif;
                            $isSelected = ($izbranDijak && (int)$d["id_uporabnik"] === (int)$izbranDijak) ? "selected" : "";
                        ?>
                        <option value="<?= $d["id_uporabnik"] ?>" <?= $isSelected ?>>
                            <?= e($d["priimek"] . " " . $d["ime"]) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($curGroup !== null) echo "</optgroup>"; ?>
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
                    <input type="text" name="naslov_ocene" placeholder="npr. 1. pisni preizkus" required>
                </label>

                <label>
                    Opis
                    <textarea name="opis" placeholder="Komentar ali vsebina ocenjevanja..."></textarea>
                </label>

                <label>
                    Datum ocenitve
                    <input type="date" name="datum_ocenitve" value="<?= date("Y-m-d") ?>" required>
                </label>

                <button class="btn blue">Dodaj oceno</button>
            </form>
        </section>


    <?php endif; ?>


    <!-- GLAVNI PREGLED OCEN Z DVOSTOPENJSKIM FILTROM (ODDELEK -> DIJAK) -->
    <section class="panel full">
        <h2>
            <?= $user["vloga"] === "ucitelj" ? "Pregled ocen dijakov" : "Pregled vseh ocen" ?>
        </h2>

        <!-- DVOSTOPENJSKI FILTER: 1. ODDELEK, 2. DIJAK -->
        <div style="background: #f8fbff; border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; margin-bottom: 22px;">
            <form method="GET" action="ocene.php" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">

                <label style="display: grid; gap: 5px; font-size: 13px; font-weight: 700;">
                    1. Izbira oddelka
                    <select name="oddelek" onchange="this.form.submit()" style="min-width: 230px; padding: 8px; border: 1px solid var(--border); border-radius: 7px;">
                        <option value="">-- Izberite oddelek --</option>
                        <?php foreach ($oddelki as $o): ?>
                        <option value="<?= $o["id_oddelek"] ?>" <?= ($izbranOddelek === (int)$o["id_oddelek"]) ? "selected" : "" ?>>
                            <?= e($o["ime"]) ?> (<?= e($o["program"]) ?>, <?= (int)$o["letnik"] ?>. letnik)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="display: grid; gap: 5px; font-size: 13px; font-weight: 700;">
                    2. Izbira dijaka
                    <select name="dijak" onchange="this.form.submit()" <?= empty($izbranOddelek) ? "disabled" : "" ?> style="min-width: 230px; padding: 8px; border: 1px solid var(--border); border-radius: 7px;">
                        <?php if (empty($izbranOddelek)): ?>
                            <option value="">-- Najprej izberite oddelek --</option>
                        <?php else: ?>
                            <option value="">-- Vsi dijaki v oddelku (seznam) --</option>
                            <?php foreach ($dijakiOddelka as $do): ?>
                            <option value="<?= $do["id_uporabnik"] ?>" <?= ($izbranDijak === (int)$do["id_uporabnik"]) ? "selected" : "" ?>>
                                <?= e($do["priimek"] . " " . $do["ime"]) ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn blue small">Prikaži</button>
                    <?php if ($izbranOddelek || $izbranDijak): ?>
                        <a href="ocene.php" class="btn small" style="background: #e2e8f0; color: #334155;">Ponastavi filter</a>
                    <?php endif; ?>
                </div>

            </form>
        </div>


        <!-- PRIKAZ GLEDE NA IZBIRO -->

        <?php if (!$izbranOddelek): ?>

            <!-- 1. STANJE: NI IZBRANEGA ODDEKA -->
            <div style="text-align: center; padding: 42px 20px; background: #fff; border: 1px dashed var(--border); border-radius: 10px;">
                <p style="font-size: 16px; font-weight: 700; color: var(--blue-dark); margin-bottom: 6px;">
                    Najprej izberite oddelek
                </p>
                <p style="color: var(--muted); font-size: 13px; max-width: 520px; margin: 0 auto;">
                    Za pregleden pregled nad ocenami prosimo izberite oddelek v zgornjem spustnem seznamu. Po izbiri oddelka boste lahko pregledali vse dijake tega oddelka in nato izbrali določenega dijaka.
                </p>
            </div>

        <?php elseif ($izbranOddelek && !$izbranDijak): ?>

            <!-- 2. STANJE: IZBRAN ODDELEK, NI PA ŠE IZBRAN DOLOČEN DIJAK -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0; font-size: 17px; color: var(--blue-dark);">
                    Seznam dijakov oddelka <?= e($oddelekInfo["ime"]) ?>
                    <small style="color: var(--muted); font-weight: normal; font-size: 13px;">(<?= e($oddelekInfo["program"]) ?>, <?= (int)$oddelekInfo["letnik"] ?>. letnik)</small>
                </h3>
                <span class="badge-ok" style="font-size: 13px;"><?= count($dijakiOddelka) ?> dijakov</span>
            </div>

            <?php if (empty($dijakiOddelka)): ?>
                <p style="color: var(--muted); padding: 20px; background: #fbfdff; border-radius: 8px; border: 1px solid var(--border);">
                    V tem oddelku trenutno ni vpisanih dijakov.
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Dijak</th>
                            <?php if ($user["vloga"] === "ucitelj"): ?>
                                <th>Predmeti pri vas</th>
                                <th>Vaše ocene</th>
                            <?php else: ?>
                                <th>Št. ocen</th>
                            <?php endif; ?>
                            <th>Povprečje</th>
                            <th style="text-align: right;">Dejanja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dijakiOddelka as $idx => $d): 
                            $dId = (int)$d["id_uporabnik"];
                            $stOcen = $dijakStatsMap[$dId]["st_ocen"] ?? 0;
                            $povpr = isset($dijakStatsMap[$dId]["povprecje"]) ? number_format((float)$dijakStatsMap[$dId]["povprecje"], 2) : null;
                            $predmetiDijaka = $dijakPredmetiMap[$dId] ?? [];
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= e($d["priimek"] . " " . $d["ime"]) ?></strong>
                            </td>

                            <?php if ($user["vloga"] === "ucitelj"): ?>
                                <td>
                                    <?php if (!empty($predmetiDijaka)): ?>
                                        <small><?= e(implode(", ", $predmetiDijaka)) ?></small>
                                    <?php else: ?>
                                        <small style="color: var(--muted);">Ni vpisan k vašim predmetom</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $stOcen > 0 ? '<strong>' . $stOcen . '</strong>' : '<span style="color:var(--muted)">0</span>' ?>
                                </td>
                            <?php else: ?>
                                <td>
                                    <?= $stOcen > 0 ? '<strong>' . $stOcen . '</strong>' : '<span style="color:var(--muted)">0</span>' ?>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?php if ($povpr !== null): ?>
                                    <span class="badge-ok" style="font-size: 13px; font-weight: 700;"><?= $povpr ?></span>
                                <?php else: ?>
                                    <span style="color: var(--muted); font-size: 12px;">Brez ocen</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right; white-space: nowrap;">
                                <a href="ocene.php?oddelek=<?= $izbranOddelek ?>&dijak=<?= $dId ?>" class="btn blue small">
                                    Poglej ocene &rarr;
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($izbranOddelek && $izbranDijak && $izbraniDijakPodatki): ?>

            <!-- 3. STANJE: IZBRAN DOLOČEN DIJAK -->
            <div style="margin-bottom: 15px;">
                <a href="ocene.php?oddelek=<?= $izbranOddelek ?>" class="back-link" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600;">
                    &larr; Nazaj na seznam oddelka <?= e($oddelekInfo["ime"] ?? "") ?>
                </a>
            </div>

            <!-- KARTICA DIJAKA -->
            <div style="background: #f8fbff; border: 1px solid #d0e0f2; border-radius: 12px; padding: 18px 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0 0 5px; font-size: 19px; color: var(--blue-dark);">
                        <?= e($izbraniDijakPodatki["priimek"] . " " . $izbraniDijakPodatki["ime"]) ?>
                    </h3>
                    <p style="margin: 0; color: var(--muted); font-size: 13px;">
                        Oddelek: <strong><?= e($izbraniDijakPodatki["oddelek_ime"] ?? "Neznano") ?></strong>
                        <?php if (!empty($izbraniDijakPodatki["program"])): ?>
                            &bull; <?= e($izbraniDijakPodatki["program"]) ?>, <?= (int)$izbraniDijakPodatki["letnik"] ?>. letnik
                        <?php endif; ?>
                    </p>
                </div>

                <div style="display: flex; gap: 24px; align-items: center;">
                    <div style="text-align: center;">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--muted); font-weight: 600;">Št. ocen</div>
                        <div style="font-size: 22px; font-weight: 700; color: var(--blue-dark);"><?= count($oceneDijaka) ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--muted); font-weight: 600;">
                            <?= $user["vloga"] === "ucitelj" ? "Vaše povprečje" : "Skupno povprečje" ?>
                        </div>
                        <div style="font-size: 22px; font-weight: 700; color: <?= $povprecjeDijaka !== null ? 'var(--blue)' : 'var(--muted)' ?>;">
                            <?= $povprecjeDijaka !== null ? number_format($povprecjeDijaka, 2) : "—" ?>
                        </div>
                    </div>
                </div>
            </div>

            <h3 style="margin: 18px 0 10px; font-size: 15px; color: var(--blue-dark);">
                <?= $user["vloga"] === "ucitelj" ? "Ocene dijaka pri vaših predmetih" : "Vse ocene dijaka" ?>
            </h3>

            <?php if (empty($oceneDijaka)): ?>
                <p style="color: var(--muted); padding: 20px; background: #fbfdff; border-radius: 8px; border: 1px solid var(--border);">
                    Ta dijak še nima vnesenih ocen<?= $user["vloga"] === "ucitelj" ? " pri vaših predmetih" : "" ?>.
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Predmet</th>
                            <th>Ocena</th>
                            <th>Naslov ocene</th>
                            <th>Opis</th>
                            <th>Datum</th>
                            <?php if ($user["vloga"] === "admin"): ?>
                                <th>Učitelj</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($oceneDijaka as $o): ?>
                        <tr>
                            <td><strong><?= e($o["predmet"]) ?></strong></td>
                            <td>
                                <span class="badge-ok" style="font-size: 14px; padding: 4px 11px;">
                                    <strong><?= e($o["ocena"]) ?></strong>
                                </span>
                            </td>
                            <td><?= e($o["naslov_ocene"]) ?></td>
                            <td><?= e($o["opis"]) ?></td>
                            <td><?= e($o["datum_ocenitve"]) ?></td>
                            <?php if ($user["vloga"] === "admin"): ?>
                                <td><?= e($o["ucitelj_priimek"] . " " . $o["ucitelj_ime"]) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>

    </section>

<?php endif; ?>

</div>

<?php pageEnd(); ?>
