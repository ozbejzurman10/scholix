<?php

require_once "funkcije.php";

requireLogin();

$user = getUser();

// Urejanje govorilnih ur in kabineta (učitelj za svoj profil ali administrator za kateregakoli učitelja)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "posodobi_govorilne") {
        $ciljniUciteljId = (int) ($_POST["ucitelj_id"] ?? 0);
        $kabinet = trim($_POST["kabinet"] ?? "");
        $telefon = trim($_POST["telefon"] ?? "");
        $govorilneUre = trim($_POST["govorilne_ure"] ?? "");

        $lahkoUreja = false;
        if (($user["vloga"] ?? "") === "admin" && $ciljniUciteljId > 0) {
            $lahkoUreja = true;
        } elseif (($user["vloga"] ?? "") === "ucitelj" && $ciljniUciteljId === (int) $user["id_uporabnik"]) {
            $lahkoUreja = true;
        }

        if ($lahkoUreja) {
            $stmtUpd = $conn->prepare("
                UPDATE Uporabnik
                SET kabinet = ?, telefon = ?, govorilne_ure = ?
                WHERE id_uporabnik = ? AND vloga = 'ucitelj'
            ");
            $stmtUpd->execute([
                $kabinet !== "" ? $kabinet : null,
                $telefon !== "" ? $telefon : null,
                $govorilneUre !== "" ? $govorilneUre : null,
                $ciljniUciteljId
            ]);
            flash("Podatki o kabinetu in govorilnih urah so bili uspešno posodobljeni.");
        }
        redirect("govorilne.php");
    }
}

// Ugotovi, kateri profesorji poučujejo prijavljenega dijaka (iz urnika oddelka, razredništva, gradiv in nalog)
$mojiProfesorjiIds = [];
$oddelekNazivDijaka = "";
$mojiProfesorjiPredmeti = []; // ucitelj_id => predmeti, ki jih uči temu oddelku

if ($user["vloga"] === "dijak") {
    $oddelekIdDijak = (int) ($user["oddelek_id"] ?? 0);
    if ($oddelekIdDijak > 0) {
        $stmtOdd = $conn->prepare("SELECT ime, razrednik_id FROM Oddelek WHERE id_oddelek = ?");
        $stmtOdd->execute([$oddelekIdDijak]);
        $oddRow = $stmtOdd->fetch();
        if ($oddRow) {
            $oddelekNazivDijaka = (string) ($oddRow["ime"] ?? "");
            $razrednikId = (int) ($oddRow["razrednik_id"] ?? 0);
            if ($razrednikId > 0) {
                $mojiProfesorjiIds[$razrednikId] = true;
                $mojiProfesorjiPredmeti[$razrednikId] = "Razrednik";
            }
        }

        // 1. Profesorji po urniku tega oddelka (z natančnimi predmeti za ta oddelek)
        $stmtMp = $conn->prepare("
            SELECT 
                urn.ucitelj_id,
                GROUP_CONCAT(DISTINCT p.naziv ORDER BY p.naziv SEPARATOR ', ') AS oddelcni_predmeti
            FROM Urnik urn
            JOIN Predmet p ON p.id_predmet = urn.predmet_id
            WHERE urn.oddelek_id = ? AND p.naziv <> 'Razredna ura'
            GROUP BY urn.ucitelj_id
        ");
        $stmtMp->execute([$oddelekIdDijak]);
        foreach ($stmtMp->fetchAll() as $row) {
            $tid = (int) $row["ucitelj_id"];
            $mojiProfesorjiIds[$tid] = true;
            if (isset($mojiProfesorjiPredmeti[$tid]) && $mojiProfesorjiPredmeti[$tid] === "Razrednik") {
                $mojiProfesorjiPredmeti[$tid] = "Razrednik · " . $row["oddelcni_predmeti"];
            } else {
                $mojiProfesorjiPredmeti[$tid] = $row["oddelcni_predmeti"];
            }
        }

        // 2. Profesorji, ki so za ta oddelek objavili gradiva ali naloge
        $stmtMpGradiva = $conn->prepare("
            SELECT DISTINCT g.ucitelj_id, p.naziv
            FROM Gradivo g
            JOIN Gradivo_Oddelek go ON go.gradivo_id = g.id_gradivo
            JOIN Predmet p ON p.id_predmet = g.predmet_id
            WHERE go.oddelek_id = ?
        ");
        $stmtMpGradiva->execute([$oddelekIdDijak]);
        foreach ($stmtMpGradiva->fetchAll() as $row) {
            $tid = (int) $row["ucitelj_id"];
            $mojiProfesorjiIds[$tid] = true;
            if (!isset($mojiProfesorjiPredmeti[$tid])) {
                $mojiProfesorjiPredmeti[$tid] = $row["naziv"];
            }
        }

        $stmtMpNaloge = $conn->prepare("
            SELECT DISTINCT n.ucitelj_id, p.naziv
            FROM Naloga n
            JOIN Naloga_Oddelek no ON no.naloga_id = n.id_naloga
            JOIN Predmet p ON p.id_predmet = n.predmet_id
            WHERE no.oddelek_id = ?
        ");
        $stmtMpNaloge->execute([$oddelekIdDijak]);
        foreach ($stmtMpNaloge->fetchAll() as $row) {
            $tid = (int) $row["ucitelj_id"];
            $mojiProfesorjiIds[$tid] = true;
            if (!isset($mojiProfesorjiPredmeti[$tid])) {
                $mojiProfesorjiPredmeti[$tid] = $row["naziv"];
            }
        }
    }
}

// Povezava vsakega učitelja z vsemi oddelki, ki jih poučuje (iz urnika in razredništva)
$oddelkiPoucevanjaPoUciteljih = [];
$stmtVsiOdd = $conn->query("
    SELECT DISTINCT urn.ucitelj_id, o.ime AS oddelek_ime
    FROM Urnik urn
    JOIN Oddelek o ON o.id_oddelek = urn.oddelek_id
    UNION
    SELECT DISTINCT o.razrednik_id AS ucitelj_id, o.ime AS oddelek_ime
    FROM Oddelek o
    WHERE o.razrednik_id IS NOT NULL
");
foreach ($stmtVsiOdd->fetchAll() as $row) {
    $uid = (int) $row["ucitelj_id"];
    $oddelkiPoucevanjaPoUciteljih[$uid][] = $row["oddelek_ime"];
}

// Posebne vloge na ŠC Celje – SŠ KER (vodstvo, svetovalna služba, gostujoči učitelji)
$posebneVlogeKer = [
    "Laznik|Anita" => ["naziv" => "dr. Anita Laznik", "vloga" => "Ravnateljica SŠ KER"],
    "Jančič|Jernej" => ["naziv" => "mag. Jernej Jančič", "vloga" => "Pomočnik ravnateljice"],
    "Verdev|Tjaša" => ["naziv" => "Tjaša Verdev", "vloga" => "Pomočnica ravnateljice"],
    "Stepišnik|Urška" => ["naziv" => "Urška Stepišnik", "vloga" => "Svetovalna delavka (tehnik računalništva, kemijski tehnik)"],
    "Koštomaj|Urška" => ["naziv" => "Urška Koštomaj", "vloga" => "Svetovalna delavka (elektrotehnik, elektrikar)"],
    "Boh|Eva" => ["naziv" => "dr. Eva Boh", "vloga" => ""],
    "Jelenko|Tanja" => ["naziv" => "dr. Tanja Jelenko", "vloga" => ""],
    "Lilija|Boštjan" => ["naziv" => "mag. Boštjan Lilija", "vloga" => ""],
    "Resinovič|Boštjan" => ["naziv" => "mag. Boštjan Resinovič", "vloga" => ""],
    "Tkalec|Andreja" => ["naziv" => "mag. Andreja Tkalec", "vloga" => ""],
    "Križan|Jerneja" => ["naziv" => "Jerneja Križan", "vloga" => "Gostujoča učiteljica"],
    "Povše|Darja" => ["naziv" => "Darja Povše", "vloga" => "Gostujoča učiteljica"]
];

// Pridobi vse profesorje SŠ KER s predmeti (brez Razredne ure) in morebitnim razredništvom
$vsiUciteljiGovorilne = $conn->query("
    SELECT
        u.id_uporabnik,
        u.ime,
        u.priimek,
        u.e_posta,
        u.kabinet,
        u.telefon,
        u.govorilne_ure,
        GROUP_CONCAT(DISTINCT CASE WHEN p.naziv <> 'Razredna ura' THEN p.naziv END ORDER BY p.naziv SEPARATOR ', ') AS predmeti_seznam,
        GROUP_CONCAT(DISTINCT o.ime ORDER BY o.ime SEPARATOR ', ') AS razrednik_oddelek
    FROM Uporabnik u
    LEFT JOIN Ucitelj_Predmet up ON up.ucitelj_id = u.id_uporabnik
    LEFT JOIN Predmet p ON p.id_predmet = up.predmet_id
    LEFT JOIN Oddelek o ON o.razrednik_id = u.id_uporabnik
    WHERE u.vloga = 'ucitelj'
    GROUP BY u.id_uporabnik
    ORDER BY u.priimek, u.ime
")->fetchAll();

// Če je prijavljen učitelj, poišči njegove lastne podatke
$mojUciteljZapis = null;
if ($user["vloga"] === "ucitelj") {
    foreach ($vsiUciteljiGovorilne as $row) {
        if ((int) $row["id_uporabnik"] === (int) $user["id_uporabnik"]) {
            $mojUciteljZapis = $row;
            break;
        }
    }
}

$jeAdmin = ($user["vloga"] === "admin");

pageStart("Govorilne ure in kabineti");
?>

<h1>Govorilne ure in kabineti profesorjev</h1>
<p class="panel-subtitle mb-4">
    Celoten tabelarični pregled učiteljskega zbora Srednje šole za kemijo, elektrotehniko in računalništvo (ŠC Celje – SŠ KER) z uradnimi kabineti, termini govorilnih ur in telefonskimi številkami.
</p>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-num"><?= count($vsiUciteljiGovorilne) ?></div>
        <div class="stat-label">Vsi profesorji SŠ KER</div>
    </div>
    <?php if ($user["vloga"] === "dijak"): ?>
        <div class="stat-card">
            <div class="stat-num"><?= count($mojiProfesorjiIds) ?></div>
            <div class="stat-label">Moji profesorji</div>
        </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-num text-lg">Pot na Lavo 22, Celje</div>
        <div class="stat-label">Lokacija šole (ŠC Celje – SŠ KER)</div>
    </div>
</div>

<?php if ($user["vloga"] === "ucitelj" && $mojUciteljZapis): ?>
    <section class="panel office-self-card mb-4">
        <div class="enrollment-header">
            <div>
                <span class="badge-info mb-1">Moj kabinet in termin</span>
                <h2 class="m-0 mt-1"><?= e($mojUciteljZapis["priimek"] . " " . $mojUciteljZapis["ime"]) ?></h2>
                <p class="off-hint m-0 mt-1">Tukaj lahko posodobite svoj kabinet, interno telefonsko številko in termin govorilnih ur za dijake.</p>
            </div>
        </div>

        <form method="post" class="office-self-form">
            <input type="hidden" name="action" value="posodobi_govorilne">
            <input type="hidden" name="ucitelj_id" value="<?= (int) $mojUciteljZapis["id_uporabnik"] ?>">

            <label>
                Kabinet / učilnica
                <input type="text" name="kabinet" value="<?= e($mojUciteljZapis["kabinet"] ?? "") ?>" placeholder="npr. E12, AE06, BE08" required>
            </label>

            <label>
                Termin govorilnih ur
                <input type="text" name="govorilne_ure" value="<?= e($mojUciteljZapis["govorilne_ure"] ?? "") ?>" placeholder="npr. sreda, od 8.50 do 9.35" required>
            </label>

            <label>
                Interni telefon
                <input type="text" name="telefon" value="<?= e($mojUciteljZapis["telefon"] ?? "") ?>" placeholder="npr. 03 428 58 74">
            </label>

            <div class="office-self-btn-wrap">
                <button type="submit" class="btn blue btn-h38 nowrap">Shrani moje podatke</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel office-hours-panel">
    <div class="office-toolbar">
        <div class="office-search-wrap">
            <input
                type="text"
                id="officeSearchInput"
                class="office-search-input"
                placeholder="Išči profesorja, kabinet, predmet ali dan (npr. E12, BE08, AE05, torek, matematika)..."
                oninput="filterOfficeTable()"
            >
        </div>

        <div class="office-filter-group">
            <button type="button" class="filter-pill active" data-scope="vsi" onclick="setOfficeScope('vsi', this)">
                Vsi profesorji SŠ KER (<?= count($vsiUciteljiGovorilne) ?>)
            </button>
            <?php if ($user["vloga"] === "dijak" && !empty($mojiProfesorjiIds)): ?>
                <button type="button" class="filter-pill" data-scope="moji" onclick="setOfficeScope('moji', this)">
                    Samo moji profesorji (<?= count($mojiProfesorjiIds) ?>)
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="tabela-ovoj">
        <table class="office-hours-table" id="officeHoursTable">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th>Profesor</th>
                    <th>Kabinet</th>
                    <th>Govorilne ure</th>
                    <th>Telefon</th>
                    <th>E-pošta</th>
                    <?php if ($jeAdmin): ?>
                        <th class="col-center">Urejanje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $zapSt = 1; ?>
                <?php foreach ($vsiUciteljiGovorilne as $prof): ?>
                    <?php
                    $profId = (int) $prof["id_uporabnik"];
                    $jeMojProf = isset($mojiProfesorjiIds[$profId]);
                    $govText = trim((string) ($prof["govorilne_ure"] ?? ""));
                    $kabText = trim((string) ($prof["kabinet"] ?? ""));
                    $telText = trim((string) ($prof["telefon"] ?? ""));
                    $predmetiText = trim((string) ($prof["predmeti_seznam"] ?? ""));
                    $razrednikText = trim((string) ($prof["razrednik_oddelek"] ?? ""));
                    $mojPredmetText = $mojiProfesorjiPredmeti[$profId] ?? "";
                    $oddelkiPoucuje = $oddelkiPoucevanjaPoUciteljih[$profId] ?? [];
                    $oddelkiStr = implode(",", $oddelkiPoucuje);

                    $kljucVloge = $prof["priimek"] . "|" . $prof["ime"];
                    $posebna = $posebneVlogeKer[$kljucVloge] ?? null;
                    $prikaznoIme = $posebna ? $posebna["naziv"] : ($prof["ime"] . " " . $prof["priimek"]);
                    $posebnaVlogaOpis = $posebna["vloga"] ?? "";

                    $podrocjePrikaz = $predmetiText;
                    if ($podrocjePrikaz === "" && $posebnaVlogaOpis !== "") {
                        $podrocjePrikaz = $posebnaVlogaOpis;
                    } elseif ($podrocjePrikaz !== "" && $posebnaVlogaOpis !== "") {
                        $podrocjePrikaz = $posebnaVlogaOpis . " · " . $podrocjePrikaz;
                    } elseif ($podrocjePrikaz === "") {
                        $podrocjePrikaz = "Strokovni oz. splošni predmeti SŠ KER";
                    }

                    $govLower = mb_strtolower($govText, "UTF-8");
                    $searchBlob = mb_strtolower(
                        $prof["priimek"] . " " . $prof["ime"] . " " . $prikaznoIme . " " . $kabText . " " . $govText . " " . $telText . " " . $podrocjePrikaz . " " . $razrednikText . " " . $mojPredmetText . " " . implode(" ", $oddelkiPoucuje) . " " . $prof["e_posta"],
                        "UTF-8"
                    );
                    ?>
                    <tr
                        class="office-table-row <?= $jeMojProf ? "office-row-my" : "" ?>"
                        data-my="<?= $jeMojProf ? "1" : "0" ?>"
                        data-oddelki="<?= e($oddelkiStr) ?>"
                        data-day="<?= e($govLower) ?>"
                        data-search="<?= e($searchBlob) ?>"
                    >
                        <td class="col-num text-muted font-bold office-row-index"><?= $zapSt++ ?>.</td>
                        <td>
                            <div class="office-table-teacher">
                                <strong><?= e($prof["priimek"] . " " . $prof["ime"]) ?></strong>
                                <?php if ($jeMojProf): ?>
                                    <span class="office-my-badge">Moj profesor</span>
                                <?php endif; ?>
                                <?php if ($razrednikText !== ""): ?>
                                    <span class="badge-class-chip">Razrednik <?= e($razrednikText) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($jeMojProf && $mojPredmetText !== ""): ?>
                                <div class="text-xs text-primary font-semibold mt-1">
                                    Poučuje pri <?= e($oddelekNazivDijaka) ?>: <?= e($mojPredmetText) ?>
                                </div>
                            <?php elseif ($podrocjePrikaz !== ""): ?>
                                <div class="text-xs text-muted mt-1">
                                    <?= e($podrocjePrikaz) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <span class="office-kabinet-badge"><?= e($kabText !== "" ? $kabText : "—") ?></span>
                        </td>
                        <td class="office-table-hours">
                            <strong><?= e($govText !== "" ? $govText : "po dogovoru") ?></strong>
                        </td>
                        <td class="nowrap">
                            <?= e($telText !== "" ? $telText : "—") ?>
                        </td>
                        <td class="nowrap">
                            <a href="mailto:<?= e($prof["e_posta"]) ?>" class="office-contact-mail"><?= e($prof["e_posta"]) ?></a>
                        </td>
                        <?php if ($jeAdmin): ?>
                            <td class="col-center">
                                <details class="schedule-exam-quick">
                                    <summary>Uredi</summary>
                                    <form method="post" class="schedule-exam-quick-form">
                                        <input type="hidden" name="action" value="posodobi_govorilne">
                                        <input type="hidden" name="ucitelj_id" value="<?= $profId ?>">
                                        <input type="text" name="kabinet" value="<?= e($kabText) ?>" placeholder="Kabinet (npr. E12)">
                                        <input type="text" name="govorilne_ure" value="<?= e($govText) ?>" placeholder="Govorilne ure">
                                        <input type="text" name="telefon" value="<?= e($telText) ?>" placeholder="Telefon">
                                        <button type="submit" class="btn small blue">Shrani</button>
                                    </form>
                                </details>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="officeEmptyState" class="empty-filter-state d-none">
        Za izbrane filtre ni najdenega nobenega profesorja.
    </div>
</section>

<script>
var currentOfficeScope = 'vsi';

function setOfficeScope(scope, btn) {
    currentOfficeScope = scope;
    var group = btn.parentElement.querySelectorAll('.filter-pill');
    for (var i = 0; i < group.length; i++) {
        group[i].classList.remove('active');
    }
    btn.classList.add('active');
    filterOfficeTable();
}

function filterOfficeTable() {
    var searchEl = document.getElementById('officeSearchInput');
    var query = searchEl ? searchEl.value.toLowerCase().trim() : '';
    var rows = document.querySelectorAll('#officeHoursTable tbody .office-table-row');
    var visibleCount = 0;

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var isMy = row.getAttribute('data-my') === '1';
        var searchText = row.getAttribute('data-search') || '';

        var matchScope = (currentOfficeScope === 'vsi') || isMy;
        var matchSearch = (query === '') || (searchText.indexOf(query) !== -1);

        if (matchScope && matchSearch) {
            row.classList.remove('d-none');
            visibleCount++;
            var idxCell = row.querySelector('.office-row-index');
            if (idxCell) {
                idxCell.textContent = visibleCount + '.';
            }
        } else {
            row.classList.add('d-none');
        }
    }

    var emptyEl = document.getElementById('officeEmptyState');
    if (emptyEl) {
        if (visibleCount === 0) {
            emptyEl.classList.remove('d-none');
        } else {
            emptyEl.classList.add('d-none');
        }
    }
}
</script>

<?php pageEnd(); ?>
