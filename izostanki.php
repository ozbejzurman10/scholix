<?php

require_once "functions.php";

requireLogin();

$user = getUser();


// ==============================
// PODATKI O RAZREDNIŠTVU
// ==============================

$jeAdmin   = ($user["vloga"] === "admin");
$jeUcitelj = ($user["vloga"] === "ucitelj");
$jeDijak   = ($user["vloga"] === "dijak");

// Katerim oddelkom je prijavljeni učitelj razrednik
$mojiRazredi = [];
$mojiRazrediIds = [];

if ($jeUcitelj) {
    $stMoji = $conn->prepare("SELECT id_oddelek, ime, letnik, program FROM Oddelek WHERE razrednik_id = ? ORDER BY letnik, ime");
    $stMoji->execute([$user["id_uporabnik"]]);
    $mojiRazredi = $stMoji->fetchAll();
    $mojiRazrediIds = array_map('intval', array_column($mojiRazredi, "id_oddelek"));
}


// ==============================
// AKCIJE – DODAJ / UREDI IZOSTANEK
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Samo admin ali učitelj sme dodajati/urejati
    if (!$jeAdmin && !$jeUcitelj) {
        flash("Nimate dovoljenja.");
        redirect("izostanki.php");
    }

    $action = $_POST["action"] ?? "";

    try {

        if ($action === "dodaj_izostanek") {

            $dijak_id = (int) ($_POST["dijak_id"] ?? 0);
            $datum    = $_POST["datum"] ?? "";
            $ura      = (int) ($_POST["ura"] ?? 0);
            $status   = $_POST["status"] ?? "";
            $opomba   = trim($_POST["opomba"] ?? "");

            // Preveri, da je veljavna vloga dijaka in pridobi oddelek ter razrednika
            $stmt = $conn->prepare(
                "SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id,
                        o.ime AS oddelek_ime, o.razrednik_id,
                        r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
                 FROM Uporabnik u
                 LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                 LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
                 WHERE u.id_uporabnik = ? AND u.vloga = 'dijak'"
            );
            $stmt->execute([$dijak_id]);
            $dijakPodatki = $stmt->fetch();

            if (!$dijakPodatki) {
                throw new RuntimeException("Neveljaven dijak.");
            }

            if (!in_array($status, ["neopraviceno", "opraviceno"], true)) {
                throw new RuntimeException("Neveljaven status.");
            }

            if ($ura < 0 || $ura > 12) {
                throw new RuntimeException("Ura mora biti med 0 in 12.");
            }

            // Profesor lahko izostanek označi kot opravičen SAMO za oddelke, kjer je razrednik!
            if ($status === "opraviceno" && $jeUcitelj) {
                $jeRazrednik = !empty($dijakPodatki["oddelek_id"]) && in_array((int)$dijakPodatki["oddelek_id"], $mojiRazrediIds, true);
                if (!$jeRazrednik) {
                    $razrednikIme = trim(($dijakPodatki["razrednik_priimek"] ?? "") . " " . ($dijakPodatki["razrednik_ime"] ?? ""));
                    $razrednikTekst = $razrednikIme !== "" ? " ($razrednikIme)" : "";
                    throw new RuntimeException("Izostanek lahko kot opravičen označi le razrednik oddelka " . ($dijakPodatki["oddelek_ime"] ?: "") . $razrednikTekst . ".");
                }
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

            flash("Izostanek je bil uspešno dodan.");
        }


        elseif ($action === "uredi_izostanek") {

            $id        = (int) ($_POST["id"] ?? 0);
            $novStatus = $_POST["status"] ?? "";
            $opomba    = trim($_POST["opomba"] ?? "");

            if (!in_array($novStatus, ["neopraviceno", "opraviceno"], true)) {
                throw new RuntimeException("Neveljaven status.");
            }

            // Pridobi trenutni izostanek z dijaki in razrednikom
            $stmt = $conn->prepare("
                SELECT i.*, u.oddelek_id, o.ime AS oddelek_ime, o.razrednik_id,
                       r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
                FROM Izostanek i
                JOIN Uporabnik u ON u.id_uporabnik = i.dijak_id
                LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
                LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
                WHERE i.id_izostanek = ?
            ");
            $stmt->execute([$id]);
            $trenutni = $stmt->fetch();

            if (!$trenutni) {
                throw new RuntimeException("Izostanek ne obstaja.");
            }

            if ($jeUcitelj) {
                $jeRazrednik = !empty($trenutni["oddelek_id"]) && in_array((int)$trenutni["oddelek_id"], $mojiRazrediIds, true);
                $razrednikIme = trim(($trenutni["razrednik_priimek"] ?? "") . " " . ($trenutni["razrednik_ime"] ?? ""));
                $razrednikTekst = $razrednikIme !== "" ? " ($razrednikIme)" : "";

                // Če profesor ni razrednik, ne sme opravičiti izostanka
                if (!$jeRazrednik && $novStatus === "opraviceno") {
                    throw new RuntimeException("Izostanek lahko opraviči le razrednik oddelka " . ($trenutni["oddelek_ime"] ?: "") . $razrednikTekst . ".");
                }

                // Če profesor ni razrednik, tudi ne sme spreminjati statusa že opravičenega izostanka
                if (!$jeRazrednik && $trenutni["status"] === "opraviceno" && $novStatus !== "opraviceno") {
                    throw new RuntimeException("Status že opravičenega izostanka lahko spreminja le razrednik oddelka " . ($trenutni["oddelek_ime"] ?: "") . $razrednikTekst . ".");
                }
            }

            $stmt = $conn->prepare(
                "UPDATE Izostanek
                 SET status = ?, opomba = ?
                 WHERE id_izostanek = ?"
            );

            $stmt->execute([$novStatus, $opomba, $id]);

            flash("Izostanek je bil posodobljen.");
        }


        elseif ($action === "izbrisi_izostanek" && $jeAdmin) {

            $id = (int) ($_POST["id"] ?? 0);

            $stmt = $conn->prepare(
                "DELETE FROM Izostanek WHERE id_izostanek = ?"
            );

            $stmt->execute([$id]);

            flash("Izostanek je bil izbrisan.");
        }

    } catch (Throwable $e) {

        flash("Napaka: " . $e->getMessage());
    }

    $retQuery = [];
    if (!empty($_POST["filter_oddelek"])) $retQuery["oddelek"] = $_POST["filter_oddelek"];
    if (!empty($_POST["filter_status"]))  $retQuery["status_filter"] = $_POST["filter_status"];
    $retUrl = "izostanki.php" . ($retQuery ? "?" . http_build_query($retQuery) : "");

    redirect($retUrl);
}


// ==============================
// PRIDOBIVANJE PODATKOV
// ==============================

$izbranFilterOddelek = isset($_GET["oddelek"]) ? (int)$_GET["oddelek"] : 0;
$izbranFilterStatus  = $_GET["status_filter"] ?? "";

// DIJAK – svoje izostanke in podatke o svojem razredniku
if ($jeDijak) {

    $stmt = $conn->prepare(
        "SELECT * FROM Izostanek
         WHERE dijak_id = ?
         ORDER BY datum DESC, ura"
    );
    $stmt->execute([$user["id_uporabnik"]]);
    $izostanki = $stmt->fetchAll();

    $dijaki = [];
    $oddelki = [];

    $dijakRazrednikInfo = null;
    if (!empty($user["oddelek_id"])) {
        $stD = $conn->prepare("
            SELECT o.ime AS oddelek_ime, o.letnik, o.program,
                   r.ime AS razrednik_ime, r.priimek AS razrednik_priimek, r.e_posta AS razrednik_email
            FROM Oddelek o
            LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
            WHERE o.id_oddelek = ?
        ");
        $stD->execute([$user["oddelek_id"]]);
        $dijakRazrednikInfo = $stD->fetch();
    }
}

// UČITELJ ali ADMIN
else {

    $sql = "
        SELECT i.*, u.ime, u.priimek, u.oddelek_id,
               o.ime AS oddelek_ime, o.razrednik_id,
               r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
        FROM Izostanek i
        JOIN Uporabnik u ON u.id_uporabnik = i.dijak_id
        LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
        LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
        WHERE 1=1
    ";
    $params = [];

    if ($izbranFilterOddelek > 0) {
        $sql .= " AND u.oddelek_id = ?";
        $params[] = $izbranFilterOddelek;
    }

    if (in_array($izbranFilterStatus, ["neopraviceno", "opraviceno"], true)) {
        $sql .= " AND i.status = ?";
        $params[] = $izbranFilterStatus;
    }

    $sql .= " ORDER BY i.datum DESC, i.ura";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $izostanki = $stmt->fetchAll();

    // Vsi oddelki za filter
    $oddelki = $conn->query("
        SELECT o.*, r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
        FROM Oddelek o
        LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
        ORDER BY o.letnik, o.ime
    ")->fetchAll();

    // Seznam dijakov za obrazec za dodajanje izostanka
    $dijaki = $conn->query("
        SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id,
               o.ime AS oddelek_ime, o.razrednik_id
        FROM Uporabnik u
        LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
        WHERE u.vloga = 'dijak'
        ORDER BY o.letnik, o.ime, u.priimek, u.ime
    ")->fetchAll();
}


pageStart("Izostanki");

?>

<h1>Izostanki</h1>

<?php if ($jeDijak && $dijakRazrednikInfo): ?>
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
        <div>
            <span style="font-size: 13px; color: #1e40af; font-weight: 700;">Vaš oddelek:</span>
            <strong style="color: #1e3a8a; font-size: 15px; margin-left: 4px;"><?= e($dijakRazrednikInfo["oddelek_ime"]) ?></strong>
            <span style="color: #64748b; font-size: 13px;">(<?= e($dijakRazrednikInfo["program"]) ?>)</span>
        </div>
        <div>
            <span style="font-size: 13px; color: #1e40af; font-weight: 700;">Razrednik:</span>
            <strong style="color: #1e3a8a; font-size: 15px; margin-left: 4px;">
                <?= e($dijakRazrednikInfo["razrednik_priimek"] . " " . $dijakRazrednikInfo["razrednik_ime"]) ?>
            </strong>
            <?php if (!empty($dijakRazrednikInfo["razrednik_email"])): ?>
                <small style="color: #64748b; margin-left: 6px;">&middot; <?= e($dijakRazrednikInfo["razrednik_email"]) ?></small>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($jeUcitelj): ?>
    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;">
        <?php if (!empty($mojiRazredi)): ?>
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
                <div>
                    <span style="display: inline-block; padding: 2px 8px; border-radius: 6px; background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 700; margin-right: 8px;">
                        Razrednik
                    </span>
                    <strong style="color: var(--blue-dark); font-size: 14px;">
                        Ste razrednik oddelka: <?= e(implode(", ", array_column($mojiRazredi, "ime"))) ?>
                    </strong>
                    <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted);">
                        Kot razrednik lahko izostanke <strong>opravičite samo dijakom svojega oddelka</strong>. Pri ostalih oddelkih lahko le zabeležite neopravičen izostanek.
                    </p>
                </div>
                <div>
                    <?php foreach ($mojiRazredi as $mr): ?>
                        <a href="izostanki.php?oddelek=<?= (int)$mr["id_oddelek"] ?>" class="btn small" style="background: #fff; border: 1px solid var(--border);">
                            Prikaži <?= e($mr["ime"]) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div>
                <strong style="color: var(--blue-dark); font-size: 14px;">Profesor brez dodeljenega razredništva</strong>
                <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted);">
                    Niste določeni kot razrednik nobenega oddelka na ŠC KER. Izostanke lahko vnašate (kot neopravičene), status <strong>Opravičeno</strong> pa lahko potrdi le pristojni razrednik oddelka.
                </p>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="admin-grid">

<?php if ($jeAdmin || $jeUcitelj): ?>

    <!-- DODAJ IZOSTANEK -->
    <section class="panel">

        <h2>Dodaj izostanek</h2>

        <form method="POST" id="obrazec-dodaj-izostanek">

            <input type="hidden" name="action" value="dodaj_izostanek">
            <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
            <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">

            <label>
                Dijak
                <select name="dijak_id" id="izbira_dijak" required onchange="posodobiOpravicljivost()">
                    <option value="">Izberi dijaka</option>
                    <?php foreach ($dijaki as $d): ?>
                        <?php
                        $jeMojDijak = $jeUcitelj && !empty($d["oddelek_id"]) && in_array((int)$d["oddelek_id"], $mojiRazrediIds, true);
                        $dNaziv = $d["priimek"] . " " . $d["ime"] . ($d["oddelek_ime"] ? " (" . $d["oddelek_ime"] . ")" : "");
                        if ($jeMojDijak) {
                            $dNaziv .= " ★ Vaš oddelek";
                        }
                        ?>
                        <option value="<?= $d["id_uporabnik"] ?>"
                                data-oddelek-id="<?= (int)$d["oddelek_id"] ?>"
                                data-razrednik-id="<?= (int)$d["razrednik_id"] ?>"
                                data-je-moj="<?= $jeMojDijak ? '1' : '0' ?>"
                                data-oddelek-ime="<?= e($d["oddelek_ime"]) ?>">
                            <?= e($dNaziv) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Datum
                <input type="date" name="datum" value="<?= date("Y-m-d") ?>" required>
            </label>

            <label>
                Ura (0-12)
                <input type="number" name="ura" min="0" max="12" value="1" required>
            </label>

            <label>
                Status izostanka
                <select name="status" id="izbira_status" required>
                    <option value="neopraviceno">Neopravičeno</option>
                    <option value="opraviceno" id="opt_opraviceno">Opravičeno</option>
                </select>
                <small id="status_opozorilo" style="display: none; color: #b91c1c; font-size: 11px; margin-top: 4px;">
                    Kot profesor lahko izostanek opravičite le za svoj razred. Za tega dijaka lahko vnesete le neopravičen izostanek.
                </small>
            </label>

            <label>
                Opomba
                <textarea name="opomba" placeholder="Razlog izostanka, opravičilo, zdravniško potrdilo..."></textarea>
            </label>

            <button class="btn blue">Dodaj izostanek</button>

        </form>

    </section>

<?php endif; ?>


<!-- PREGLED IZOSTANKOV -->
<section class="panel full">

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
        <h2 style="margin: 0;">
            <?php if ($jeDijak): ?>
                Moji izostanki
            <?php else: ?>
                Seznam izostankov
            <?php endif; ?>
            <span style="font-size: 13px; font-weight: normal; color: var(--muted); margin-left: 8px;">
                (<?= count($izostanki) ?>)
            </span>
        </h2>

        <?php if ($jeAdmin || $jeUcitelj): ?>
            <!-- FILTER PO ODDELKU IN STATUSU -->
            <form method="get" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0;">
                <select name="oddelek" onchange="this.form.submit()" style="font-size: 12px; padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border);">
                    <option value="0">-- Vsi oddelki --</option>
                    <?php foreach ($oddelki as $o): ?>
                        <?php
                        $jeMoj = $jeUcitelj && in_array((int)$o["id_oddelek"], $mojiRazrediIds, true);
                        ?>
                        <option value="<?= (int)$o["id_oddelek"] ?>" <?= ($izbranFilterOddelek === (int)$o["id_oddelek"]) ? "selected" : "" ?>>
                            <?= e($o["ime"]) ?><?= $jeMoj ? " (Moj oddelek)" : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status_filter" onchange="this.form.submit()" style="font-size: 12px; padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border);">
                    <option value="">-- Vsi statusi --</option>
                    <option value="neopraviceno" <?= ($izbranFilterStatus === "neopraviceno") ? "selected" : "" ?>>Samo neopravičeni</option>
                    <option value="opraviceno" <?= ($izbranFilterStatus === "opraviceno") ? "selected" : "" ?>>Samo opravičeni</option>
                </select>

                <?php if ($izbranFilterOddelek > 0 || $izbranFilterStatus !== ""): ?>
                    <a href="izostanki.php" class="btn small" style="background: #e2e8f0; color: #334155; padding: 6px 10px; font-size: 12px;">Počisti</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($izostanki)): ?>
        <p style="color: var(--muted); padding: 18px 0;">Ni zabeleženih izostankov za izbrane pogoje.</p>
    <?php else: ?>

    <table>
        <thead>
            <tr>
                <?php if (!$jeDijak): ?>
                    <th>Dijak</th>
                    <th>Razred</th>
                <?php endif; ?>
                <th>Datum</th>
                <th>Ura</th>
                <th>Status</th>
                <th>Opomba</th>
                <?php if (!$jeDijak): ?>
                    <th>Razrednik</th>
                    <th style="width: 100px;"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($izostanki as $iz): ?>
            <?php
            $jeRazrednikZaTega = $jeAdmin || ($jeUcitelj && !empty($iz["oddelek_id"]) && in_array((int)$iz["oddelek_id"], $mojiRazrediIds, true));
            $razrednikImePolno = trim(($iz["razrednik_priimek"] ?? "") . " " . ($iz["razrednik_ime"] ?? ""));
            ?>
            <tr>
                <?php if (!$jeDijak): ?>
                    <td>
                        <strong><?= e($iz["priimek"] . " " . $iz["ime"]) ?></strong>
                        <?php if ($jeUcitelj && $jeRazrednikZaTega): ?>
                            <span style="display: inline-block; padding: 1px 5px; border-radius: 4px; background: #dcfce7; color: #15803d; font-size: 10px; font-weight: 700; margin-left: 4px;">
                                Vaš oddelek
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="display: inline-block; padding: 2px 6px; border-radius: 5px; background: #f1f5f9; color: #1e293b; font-size: 12px; font-weight: 600;">
                            <?= e($iz["oddelek_ime"] ?: "–") ?>
                        </span>
                    </td>
                <?php endif; ?>
                <td><?= e(date("j. n. Y", strtotime($iz["datum"]))) ?></td>
                <td><?= e($iz["ura"]) ?>. ura</td>
                <td>
                    <span class="badge-<?= $iz["status"] === "opraviceno" ? "ok" : "warn" ?>">
                        <?= $iz["status"] === "opraviceno" ? "Opravičeno" : "Neopravičeno" ?>
                    </span>
                </td>
                <td><?= e($iz["opomba"] ?: "–") ?></td>

                <?php if (!$jeDijak): ?>
                    <td>
                        <small style="color: var(--muted); font-size: 12px;">
                            <?= e($razrednikImePolno ?: "Ni določen") ?>
                        </small>
                    </td>

                    <td>
                        <details>
                            <summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: var(--blue);">Uredi</summary>

                            <form method="post" style="margin-top: 8px; display: grid; gap: 8px;">
                                <input type="hidden" name="action" value="uredi_izostanek">
                                <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">
                                <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
                                <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">

                                <?php if ($jeRazrednikZaTega): ?>
                                    <!-- Razrednik ali admin lahko prosto spreminja status -->
                                    <label style="font-size: 11px; font-weight: 700; display: grid; gap: 3px;">
                                        Status
                                        <select name="status" style="font-size: 12px; padding: 4px 6px;">
                                            <option value="neopraviceno" <?= $iz["status"] === "neopraviceno" ? "selected" : "" ?>>Neopravičeno</option>
                                            <option value="opraviceno" <?= $iz["status"] === "opraviceno" ? "selected" : "" ?>>Opravičeno</option>
                                        </select>
                                    </label>
                                <?php else: ?>
                                    <!-- Profesor ni razrednik: ne more opravičiti -->
                                    <input type="hidden" name="status" value="<?= e($iz["status"]) ?>">
                                    <div style="font-size: 11px; padding: 6px 8px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; color: #9f1239;">
                                        Status: <strong><?= $iz["status"] === "opraviceno" ? "Opravičeno" : "Neopravičeno" ?></strong>
                                        <br>
                                        <span style="font-size: 10px;">Opraviči lahko le razrednik (<?= e($razrednikImePolno) ?>).</span>
                                    </div>
                                <?php endif; ?>

                                <label style="font-size: 11px; font-weight: 700; display: grid; gap: 3px;">
                                    Opomba
                                    <input type="text" name="opomba" value="<?= e($iz["opomba"]) ?>" placeholder="Opomba" style="font-size: 12px; padding: 4px 6px;">
                                </label>

                                <button class="btn small" style="width: 100%;">Shrani</button>
                            </form>

                            <?php if ($jeAdmin): ?>
                            <form method="post" style="margin-top: 6px;">
                                <input type="hidden" name="action" value="izbrisi_izostanek">
                                <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">
                                <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
                                <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">
                                <button class="btn red small" style="width: 100%;" onclick="return confirm('Izbrišem ta izostanek?')">Izbriši</button>
                            </form>
                            <?php endif; ?>

                        </details>
                    </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>

</section>

</div>

<?php if ($jeUcitelj && !empty($mojiRazrediIds)): ?>
<script>
// Dinamično preverjanje v obrazcu za dodajanje izostanka
function posodobiOpravicljivost() {
    var sel = document.getElementById('izbira_dijak');
    var optStatusOpraviceno = document.getElementById('opt_opraviceno');
    var statusSelect = document.getElementById('izbira_status');
    var opozorilo = document.getElementById('status_opozorilo');

    if (!sel || !optStatusOpraviceno) return;

    var izbranaOpt = sel.options[sel.selectedIndex];
    var jeMoj = izbranaOpt.getAttribute('data-je-moj') === '1';

    if (sel.value === '') {
        optStatusOpraviceno.disabled = false;
        opozorilo.style.display = 'none';
        return;
    }

    if (jeMoj) {
        optStatusOpraviceno.disabled = false;
        opozorilo.style.display = 'none';
    } else {
        // Dijak ni v oddelku trenutnega učitelja -> onemogoči opravičeno
        optStatusOpraviceno.disabled = true;
        if (statusSelect.value === 'opraviceno') {
            statusSelect.value = 'neopraviceno';
        }
        opozorilo.style.display = 'block';
    }
}
document.addEventListener('DOMContentLoaded', posodobiOpravicljivost);
</script>
<?php endif; ?>

<?php pageEnd(); ?>
