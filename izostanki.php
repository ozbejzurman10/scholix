<?php

require_once "funkcije.php";

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

    // Do modula za izostanke imajo dostop le profesorji, ki so razredniki
    if (empty($mojiRazrediIds)) {
        flash("Dostop do evidence izostankov imajo le profesorji, ki so razredniki.");
        redirect("nadzorna_plosca.php");
    }
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

    if ($jeUcitelj && empty($mojiRazrediIds)) {
        flash("Dostop do evidence izostankov imajo le razredniki.");
        redirect("nadzorna_plosca.php");
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

            // Profesor lahko izostanek zabeleži SAMO za oddelke, kjer je razrednik
            if ($jeUcitelj) {
                $jeRazrednik = !empty($dijakPodatki["oddelek_id"]) && in_array((int)$dijakPodatki["oddelek_id"], $mojiRazrediIds, true);
                if (!$jeRazrednik) {
                    throw new RuntimeException("Izostanek lahko dodate le dijakom svojega oddelka.");
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
                if (!$jeRazrednik) {
                    throw new RuntimeException("Izostanke lahko urejate le dijakom svojega oddelka.");
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

// UČITELJ (ki je razrednik) ali ADMIN
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

    if ($jeUcitelj) {
        $inOdd = implode(',', array_fill(0, count($mojiRazrediIds), '?'));
        $sql .= " AND u.oddelek_id IN ($inOdd)";
        $params = array_merge($params, $mojiRazrediIds);

        if ($izbranFilterOddelek > 0 && in_array($izbranFilterOddelek, $mojiRazrediIds, true)) {
            $sql .= " AND u.oddelek_id = ?";
            $params[] = $izbranFilterOddelek;
        }
    } else {
        if ($izbranFilterOddelek > 0) {
            $sql .= " AND u.oddelek_id = ?";
            $params[] = $izbranFilterOddelek;
        }
    }

    if (in_array($izbranFilterStatus, ["neopraviceno", "opraviceno"], true)) {
        $sql .= " AND i.status = ?";
        $params[] = $izbranFilterStatus;
    }

    $sql .= " ORDER BY i.datum DESC, i.ura";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $izostanki = $stmt->fetchAll();

    // Oddelki za filter
    if ($jeUcitelj) {
        $oddelki = $mojiRazredi;
    } else {
        $oddelki = $conn->query("
            SELECT o.*, r.ime AS razrednik_ime, r.priimek AS razrednik_priimek
            FROM Oddelek o
            LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
            ORDER BY o.letnik, o.ime
        ")->fetchAll();
    }

    // Seznam dijakov za obrazec za dodajanje izostanka
    if ($jeUcitelj) {
        $inOdd = implode(',', array_fill(0, count($mojiRazrediIds), '?'));
        $stDijaki = $conn->prepare("
            SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id,
                   o.ime AS oddelek_ime, o.razrednik_id
            FROM Uporabnik u
            LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
            WHERE u.vloga = 'dijak' AND u.oddelek_id IN ($inOdd)
            ORDER BY o.letnik, o.ime, u.priimek, u.ime
        ");
        $stDijaki->execute($mojiRazrediIds);
        $dijaki = $stDijaki->fetchAll();
    } else {
        $dijaki = $conn->query("
            SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id,
                   o.ime AS oddelek_ime, o.razrednik_id
            FROM Uporabnik u
            LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
            WHERE u.vloga = 'dijak'
            ORDER BY o.letnik, o.ime, u.priimek, u.ime
        ")->fetchAll();
    }
}


pageStart("Izostanki");

?>

<h1>Izostanki</h1>

<?php if ($jeDijak && $dijakRazrednikInfo): ?>
    <div class="info-banner">
        <div>
            <span class="text-primary font-bold text-sm">Vaš oddelek:</span>
            <strong class="text-dark text-md ml-1"><?= e($dijakRazrednikInfo["oddelek_ime"]) ?></strong>
            <span class="text-muted text-sm">(<?= e($dijakRazrednikInfo["program"]) ?>)</span>
        </div>
        <div>
            <span class="text-primary font-bold text-sm">Razrednik:</span>
            <strong class="text-dark text-md ml-1">
                <?= e($dijakRazrednikInfo["razrednik_priimek"] . " " . $dijakRazrednikInfo["razrednik_ime"]) ?>
            </strong>
            <?php if (!empty($dijakRazrednikInfo["razrednik_email"])): ?>
                <small class="text-muted ml-2">&middot; <?= e($dijakRazrednikInfo["razrednik_email"]) ?></small>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($jeUcitelj): ?>
    <div class="card-banner">
        <div class="d-flex flex-wrap justify-between items-center gap-3">
            <div>
                <span class="badge-ok mr-2">
                    Razrednik
                </span>
                <strong class="text-dark text-md">
                    Ste razrednik oddelka: <?= e(implode(", ", array_column($mojiRazredi, "ime"))) ?>
                </strong>
                <p class="mt-1 text-sm text-muted">
                    Kot razrednik vodite evidenco prisotnosti ter opravičujete izostanke dijakov svojega oddelka.
                </p>
            </div>
            <?php if (count($mojiRazredi) > 1): ?>
                <div>
                    <?php foreach ($mojiRazredi as $mr): ?>
                        <a href="izostanki.php?oddelek=<?= (int)$mr["id_oddelek"] ?>" class="btn btn-white small">
                            Prikaži <?= e($mr["ime"]) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="admin-grid">

<?php if ($jeAdmin || $jeUcitelj): ?>

    <!-- DODAJ IZOSTANEK -->
    <section class="panel full">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Evidenca prisotnosti</span>
                <h2 class="m-0 mt-1">Dodaj izostanek</h2>
                <p class="off-hint m-0 mt-1">Zabeležite izostanek dijaka pri določeni šolski uri in po potrebi dodajte razlog ali opravičilo.</p>
            </div>
        </div>

        <form method="POST" id="obrazec-dodaj-izostanek" class="off-form">

            <input type="hidden" name="action" value="dodaj_izostanek">
            <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
            <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">

            <div class="izostanek-grid-top">
                <label>
                    Dijak
                    <select name="dijak_id" id="izbira_dijak" required onchange="posodobiOpravicljivost()">
                        <option value="">-- Izberi dijaka --</option>
                        <?php foreach ($dijaki as $d): ?>
                            <?php
                            $jeMojDijak = $jeUcitelj && !empty($d["oddelek_id"]) && in_array((int)$d["oddelek_id"], $mojiRazrediIds, true);
                            if ($jeUcitelj) {
                                $dNaziv = $d["priimek"] . " " . $d["ime"];
                            } else {
                                $dNaziv = $d["priimek"] . " " . $d["ime"] . ($d["oddelek_ime"] ? " (" . $d["oddelek_ime"] . ")" : "");
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
                    Ura (0–12)
                    <input type="number" name="ura" min="0" max="12" value="1" required>
                </label>

                <label>
                    Status izostanka
                    <select name="status" id="izbira_status" required>
                        <option value="neopraviceno">Neopravičeno</option>
                        <option value="opraviceno" id="opt_opraviceno">Opravičeno</option>
                    </select>
                </label>
            </div>

            <div id="status_opozorilo" class="d-none error-box-sm mt-2">
                Kot profesor lahko izostanek opravičite le za svoj razred. Za tega dijaka lahko vnesete le neopravičen izostanek.
            </div>

            <div class="izostanek-grid-bottom mt-3">
                <label>
                    Opomba / razlog izostanka (neobvezno)
                    <input type="text" name="opomba" placeholder="npr. Zdravniški pregled, zamuda, opravičilo staršev...">
                </label>

                <div class="office-self-btn-wrap">
                    <button type="submit" class="btn blue btn-h38 nowrap">+ Dodaj izostanek</button>
                </div>
            </div>

        </form>

    </section>

<?php endif; ?>


<!-- PREGLED IZOSTANKOV -->
<section class="panel full">

    <div class="panel-header-actions">
        <h2 class="m-0">
            <?php if ($jeDijak): ?>
                Moji izostanki
            <?php else: ?>
                Seznam izostankov
            <?php endif; ?>
            <span class="text-muted font-normal text-sm ml-2">
                (<?= count($izostanki) ?>)
            </span>
        </h2>

        <?php if ($jeAdmin || $jeUcitelj): ?>
            <!-- FILTER PO ODDELKU IN STATUSU -->
            <form method="get" class="d-flex flex-wrap gap-2 items-center m-0">
                <select name="oddelek" onchange="this.form.submit()" class="form-input-sm">
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

                <select name="status_filter" onchange="this.form.submit()" class="form-input-sm">
                    <option value="">-- Vsi statusi --</option>
                    <option value="neopraviceno" <?= ($izbranFilterStatus === "neopraviceno") ? "selected" : "" ?>>Samo neopravičeni</option>
                    <option value="opraviceno" <?= ($izbranFilterStatus === "opraviceno") ? "selected" : "" ?>>Samo opravičeni</option>
                </select>

                <?php if ($izbranFilterOddelek > 0 || $izbranFilterStatus !== ""): ?>
                    <a href="izostanki.php" class="btn btn-secondary small form-input-sm">Počisti</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($izostanki)): ?>
        <p class="prazno-stanje">Ni zabeleženih izostankov za izbrane pogoje.</p>
    <?php else: ?>

    <div class="tabela-ovoj"><table>
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
                    <th class="form-input-w100"></th>
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
                    </td>
                    <td>
                        <span class="badge-muted text-xs">
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
                        <small class="text-muted text-xs">
                            <?= e($razrednikImePolno ?: "Ni določen") ?>
                        </small>
                    </td>

                    <td>
                        <details>
                            <summary class="text-primary font-semibold text-xs">Uredi</summary>

                            <form method="post" class="mt-2 d-grid gap-2">
                                <input type="hidden" name="action" value="uredi_izostanek">
                                <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">
                                <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
                                <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">

                                <?php if ($jeRazrednikZaTega): ?>
                                    <!-- Razrednik ali admin lahko prosto spreminja status -->
                                    <label class="text-xs font-bold d-grid gap-1">
                                        Status
                                        <select name="status" class="form-input-sm">
                                            <option value="neopraviceno" <?= $iz["status"] === "neopraviceno" ? "selected" : "" ?>>Neopravičeno</option>
                                            <option value="opraviceno" <?= $iz["status"] === "opraviceno" ? "selected" : "" ?>>Opravičeno</option>
                                        </select>
                                    </label>
                                <?php else: ?>
                                    <!-- Profesor ni razrednik: ne more opravičiti -->
                                    <input type="hidden" name="status" value="<?= e($iz["status"]) ?>">
                                    <div class="error-box-sm">
                                        Status: <strong><?= $iz["status"] === "opraviceno" ? "Opravičeno" : "Neopravičeno" ?></strong>
                                        <br>
                                        <span class="text-xs">Opraviči lahko le razrednik (<?= e($razrednikImePolno) ?>).</span>
                                    </div>
                                <?php endif; ?>

                                <label class="text-xs font-bold d-grid gap-1">
                                    Opomba
                                    <input type="text" name="opomba" value="<?= e($iz["opomba"]) ?>" placeholder="Opomba" class="form-input-sm">
                                </label>

                                <button class="btn small btn-block">Shrani</button>
                            </form>

                            <?php if ($jeAdmin): ?>
                            <form method="post" class="mt-1">
                                <input type="hidden" name="action" value="izbrisi_izostanek">
                                <input type="hidden" name="id" value="<?= $iz["id_izostanek"] ?>">
                                <input type="hidden" name="filter_oddelek" value="<?= e($izbranFilterOddelek) ?>">
                                <input type="hidden" name="filter_status" value="<?= e($izbranFilterStatus) ?>">
                                <button class="btn red small btn-block" onclick="return confirm('Izbrišem ta izostanek?')">Izbriši</button>
                            </form>
                            <?php endif; ?>

                        </details>
                    </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>

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
        if (opozorilo) opozorilo.classList.add('d-none');
        return;
    }

    if (jeMoj) {
        optStatusOpraviceno.disabled = false;
        if (opozorilo) opozorilo.classList.add('d-none');
    } else {
        // Dijak ni v oddelku trenutnega učitelja -> onemogoči opravičeno
        optStatusOpraviceno.disabled = true;
        if (statusSelect.value === 'opraviceno') {
            statusSelect.value = 'neopraviceno';
        }
        if (opozorilo) opozorilo.classList.remove('d-none');
    }
}
document.addEventListener('DOMContentLoaded', posodobiOpravicljivost);
</script>
<?php endif; ?>

<?php pageEnd(); ?>
