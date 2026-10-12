<?php

require_once "functions.php";
requireRole("admin");

if (!function_exists('existsAdmin')) {
    function existsAdmin($table, $id) {
        global $conn;
        $id = (int) $id;
        $column = ($table === "Predmet") ? "id_predmet" : "id_uporabnik";
        $s = $conn->prepare("SELECT 1 FROM $table WHERE $column = ?");
        $s->execute([$id]);
        return (bool) $s->fetchColumn();
    }
}

$activeTab = $_GET["tab"] ?? "uporabniki";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $a = $_POST["action"] ?? "";
    $redirectTab = "uporabniki";

    try {
        if ($a === "dodaj_predmet") {
            $conn->prepare("INSERT INTO Predmet (naziv, opis) VALUES (?, ?)")
                 ->execute([trim($_POST["naziv"]), trim($_POST["opis"])]);
            flash("Predmet je bil uspešno dodan.");
            $redirectTab = "predmeti";
        }
        elseif ($a === "uredi_predmet" && existsAdmin("Predmet", $_POST["id"])) {
            $conn->prepare("UPDATE Predmet SET naziv = ?, opis = ? WHERE id_predmet = ?")
                 ->execute([trim($_POST["naziv"]), trim($_POST["opis"]), (int)$_POST["id"]]);
            flash("Predmet je bil posodobljen.");
            $redirectTab = "predmeti";
        }
        elseif ($a === "izbrisi_predmet" && existsAdmin("Predmet", $_POST["id"])) {
            $id = (int) $_POST["id"];
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM Oddana_Naloga WHERE naloga_id IN (SELECT id_naloga FROM Naloga WHERE predmet_id = ?)")->execute([$id]);
            foreach (["Ucitelj_Predmet", "Dijak_Predmet", "Gradivo", "Naloga", "Ocena", "Urnik"] as $t) {
                $conn->prepare("DELETE FROM $t WHERE predmet_id = ?")->execute([$id]);
            }
            $conn->prepare("DELETE FROM Predmet WHERE id_predmet = ?")->execute([$id]);
            $conn->commit();
            flash("Predmet in povezani zapisi so bili izbrisani.");
            $redirectTab = "predmeti";
        }
        elseif ($a === "nastavi_razrednika") {
            $oddelekId = (int) ($_POST["oddelek_id"] ?? 0);
            $razrednikId = !empty($_POST["razrednik_id"]) ? (int) $_POST["razrednik_id"] : null;

            if ($razrednikId !== null) {
                $chk = $conn->prepare("SELECT 1 FROM Uporabnik WHERE id_uporabnik = ? AND vloga = 'ucitelj'");
                $chk->execute([$razrednikId]);
                if (!$chk->fetch()) {
                    throw new RuntimeException("Izbrani uporabnik ni učitelj.");
                }
            }

            $conn->prepare("UPDATE Oddelek SET razrednik_id = ? WHERE id_oddelek = ?")
                 ->execute([$razrednikId, $oddelekId]);

            flash("Razrednik oddelka je bil posodobljen.");
            $redirectTab = "oddelki";
        }
        elseif ($a === "dodaj_uporabnika") {
            $r = $_POST["vloga"] ?? "";
            if (!in_array($r, ["ucitelj", "dijak"], true)) {
                throw new RuntimeException("Neveljavna vloga.");
            }
            $oddelekId = (!empty($_POST["oddelek_id"])) ? (int)$_POST["oddelek_id"] : null;
            if ($r !== "dijak") {
                $oddelekId = null;
            }
            $conn->prepare("INSERT INTO Uporabnik (ime, priimek, e_posta, geslo, vloga, oddelek_id) VALUES (?, ?, ?, ?, ?, ?)")
                 ->execute([
                     trim($_POST["ime"]),
                     trim($_POST["priimek"]),
                     trim($_POST["e_posta"]),
                     password_hash($_POST["geslo"], PASSWORD_DEFAULT),
                     $r,
                     $oddelekId
                 ]);
            flash("Uporabnik je bil uspešno dodan.");
            $redirectTab = "uporabniki";
        }
        elseif ($a === "uredi_uporabnika" && existsAdmin("Uporabnik", $_POST["id"])) {
            $r = $_POST["vloga"] ?? "";
            if (!in_array($r, ["admin", "ucitelj", "dijak"], true)) {
                throw new RuntimeException("Neveljavna vloga.");
            }
            $oddelekId = (!empty($_POST["oddelek_id"])) ? (int)$_POST["oddelek_id"] : null;
            if ($r !== "dijak") {
                $oddelekId = null;
            }

            $sql = "UPDATE Uporabnik SET ime = ?, priimek = ?, e_posta = ?, vloga = ?, oddelek_id = ?";
            $v = [trim($_POST["ime"]), trim($_POST["priimek"]), trim($_POST["e_posta"]), $r, $oddelekId];

            if (!empty($_POST["geslo"])) {
                $sql .= ", geslo = ?";
                $v[] = password_hash($_POST["geslo"], PASSWORD_DEFAULT);
            }
            $v[] = (int) $_POST["id"];

            $conn->prepare($sql . " WHERE id_uporabnik = ?")->execute($v);
            flash("Uporabnik je bil posodobljen.");
            $redirectTab = "uporabniki";
        }
        elseif ($a === "izbrisi_uporabnika" && existsAdmin("Uporabnik", $_POST["id"])) {
            $id = (int) $_POST["id"];
            if ($id === (int)$_SESSION["user_id"]) {
                throw new RuntimeException("Svojega računa ne morete izbrisati.");
            }
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM Oddana_Naloga WHERE dijak_id = ? OR naloga_id IN (SELECT id_naloga FROM Naloga WHERE ucitelj_id = ?)")->execute([$id, $id]);
            $conn->prepare("DELETE FROM Gradivo WHERE ucitelj_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Naloga WHERE ucitelj_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Ocena WHERE dijak_id = ? OR ucitelj_id = ?")->execute([$id, $id]);
            $conn->prepare("DELETE FROM Izostanek WHERE dijak_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Urnik WHERE ucitelj_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Ucitelj_Predmet WHERE ucitelj_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Dijak_Predmet WHERE dijak_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM Uporabnik WHERE id_uporabnik = ?")->execute([$id]);
            $conn->commit();
            flash("Uporabnik in povezani zapisi so bili izbrisani.");
            $redirectTab = "uporabniki";
        }
        elseif (in_array($a, ["povezi_ucitelja", "povezi_dijaka"], true)) {
            $teacher = ($a === "povezi_ucitelja");
            $col = $teacher ? "ucitelj_id" : "dijak_id";
            $table = $teacher ? "Ucitelj_Predmet" : "Dijak_Predmet";
            $id = (int) $_POST[$col];

            $s = $conn->prepare("SELECT 1 FROM Uporabnik WHERE id_uporabnik = ? AND vloga = ?");
            $s->execute([$id, $teacher ? "ucitelj" : "dijak"]);
            if (!$s->fetch() || !existsAdmin("Predmet", $_POST["predmet_id"])) {
                throw new RuntimeException("Neveljaven uporabnik ali predmet.");
            }
            $conn->prepare("INSERT INTO $table ($col, predmet_id) VALUES (?, ?)")->execute([$id, (int)$_POST["predmet_id"]]);
            flash("Povezava s predmetom je bila uspešno dodana.");
            $redirectTab = $teacher ? "ucitelji-predmeti" : "dijaki-predmeti";
        }
        elseif (in_array($a, ["odstrani_ucitelja", "odstrani_dijaka"], true)) {
            $teacher = ($a === "odstrani_ucitelja");
            $col = $teacher ? "ucitelj_id" : "dijak_id";
            $t = $teacher ? "Ucitelj_Predmet" : "Dijak_Predmet";
            $conn->prepare("DELETE FROM $t WHERE $col = ? AND predmet_id = ?")->execute([(int)$_POST[$col], (int)$_POST["predmet_id"]]);
            flash("Povezava je bila odstranjena.");
            $redirectTab = $teacher ? "ucitelji-predmeti" : "dijaki-predmeti";
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        flash("Napaka: " . ($e instanceof PDOException ? "preverite podatke ali podvojeno povezavo." : $e->getMessage()));
    }

    redirect("admin.php?tab=" . urlencode($redirectTab));
}

// Pridobivanje podatkov
$predmeti = $conn->query("SELECT * FROM Predmet ORDER BY naziv")->fetchAll();
$oddelki  = $conn->query("
    SELECT o.*,
           r.ime AS razrednik_ime,
           r.priimek AS razrednik_priimek,
           COUNT(DISTINCT u.id_uporabnik) AS st_dijakov
    FROM Oddelek o
    LEFT JOIN Uporabnik r ON r.id_uporabnik = o.razrednik_id
    LEFT JOIN Uporabnik u ON u.oddelek_id = o.id_oddelek AND u.vloga = 'dijak'
    GROUP BY o.id_oddelek
    ORDER BY o.letnik, o.ime
")->fetchAll();

$users = $conn->query(
    "SELECT u.*, o.ime AS oddelek_ime
     FROM Uporabnik u
     LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
     ORDER BY u.vloga, u.priimek, u.ime"
)->fetchAll();

$ucitelji = array_values(array_filter($users, fn($u) => $u["vloga"] === "ucitelj"));
$dijaki   = array_values(array_filter($users, fn($u) => $u["vloga"] === "dijak"));

$up = $conn->query(
    "SELECT u.id_uporabnik, u.ime, u.priimek, p.id_predmet, p.naziv
     FROM Ucitelj_Predmet x
     JOIN Uporabnik u ON u.id_uporabnik = x.ucitelj_id
     JOIN Predmet p ON p.id_predmet = x.predmet_id
     ORDER BY u.priimek, p.naziv"
)->fetchAll();

$dp = $conn->query(
    "SELECT u.id_uporabnik, u.ime, u.priimek, u.oddelek_id, o.ime AS oddelek_ime, p.id_predmet, p.naziv
     FROM Dijak_Predmet x
     JOIN Uporabnik u ON u.id_uporabnik = x.dijak_id
     LEFT JOIN Oddelek o ON o.id_oddelek = u.oddelek_id
     JOIN Predmet p ON p.id_predmet = x.predmet_id
     ORDER BY o.ime, u.priimek, p.naziv"
)->fetchAll();

pageStart("Administrator");

?>

<style>
/* Stil za pregledno administratorsko ploščo z zavihki */
.admin-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}
.admin-tab-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    background: #e9f0f8;
    padding: 6px;
    border-radius: 12px;
    margin-bottom: 22px;
}
.admin-tab-btn {
    border: none;
    background: transparent;
    color: var(--muted);
    padding: 10px 16px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.18s ease;
}
.admin-tab-btn:hover {
    color: var(--blue-dark);
    background: rgba(255, 255, 255, 0.6);
}
.admin-tab-btn.active {
    background: #fff;
    color: var(--blue);
    box-shadow: 0 4px 12px rgba(16, 45, 80, 0.08);
}
.tab-badge {
    background: rgba(40, 111, 199, 0.12);
    color: var(--blue);
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}
.admin-tab-btn.active .tab-badge {
    background: var(--blue);
    color: #fff;
}
.filter-box {
    background: #f8fbff;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
}
.filter-box label {
    display: grid;
    gap: 5px;
    font-size: 13px;
    font-weight: 700;
}
.filter-box input, .filter-box select {
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.collapsible-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 22px;
    box-shadow: var(--shadow);
}
.card-header-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
}
.card-header-toggle h2 {
    margin: 0;
    font-size: 17px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-toggle-btn {
    background: #f1f5f9;
    border: 1px solid var(--border);
    padding: 5px 10px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
}
.card-toggle-btn:hover {
    color: var(--blue);
    background: #e2e8f0;
}
.card-body {
    margin-top: 18px;
}
.card-body.hidden {
    display: none;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--blue);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    margin-right: 5px;
}
.student-highlight-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 15px;
    font-size: 13px;
    color: #1e40af;
}
</style>

<div class="admin-header-row">
    <div>
        <h1 style="margin: 0;">Nadzorna plošča administratorja</h1>
        <p style="margin: 5px 0 0; color: var(--muted); font-size: 14px;">
            Pregledno upravljanje dijakov, učiteljev, oddelkov in predmetov
        </p>
    </div>

    <div>
        <button type="button" id="toggle-all-tabs-btn" class="btn small" style="background: #fff; border: 1px solid var(--border); color: var(--text);" onclick="togglePrikaziVse()">
            Prikaži vse zavihke
        </button>
    </div>
</div>

<!-- GLAVNA NAVIGACIJA PO ZAVIHKIH -->
<nav class="admin-tab-nav" id="admin-tab-nav">
    <button type="button" class="admin-tab-btn <?= ($activeTab === 'uporabniki') ? 'active' : '' ?>" data-tab="tab-uporabniki" onclick="preklopiZavihek('uporabniki')">
        Uporabniki
        <span class="tab-badge"><?= count($users) ?></span>
    </button>

    <button type="button" class="admin-tab-btn <?= ($activeTab === 'dijaki-predmeti') ? 'active' : '' ?>" data-tab="tab-dijaki-predmeti" onclick="preklopiZavihek('dijaki-predmeti')">
        Dijak → Predmet
        <span class="tab-badge"><?= count($dp) ?></span>
    </button>

    <button type="button" class="admin-tab-btn <?= ($activeTab === 'ucitelji-predmeti') ? 'active' : '' ?>" data-tab="tab-ucitelji-predmeti" onclick="preklopiZavihek('ucitelji-predmeti')">
        Učitelj → Predmet
        <span class="tab-badge"><?= count($up) ?></span>
    </button>

    <button type="button" class="admin-tab-btn <?= ($activeTab === 'predmeti') ? 'active' : '' ?>" data-tab="tab-predmeti" onclick="preklopiZavihek('predmeti')">
        Predmeti
        <span class="tab-badge"><?= count($predmeti) ?></span>
    </button>

    <button type="button" class="admin-tab-btn <?= ($activeTab === 'oddelki') ? 'active' : '' ?>" data-tab="tab-oddelki" onclick="preklopiZavihek('oddelki')">
        Oddelki & Razredniki
        <span class="tab-badge"><?= count($oddelki) ?></span>
    </button>

    <button type="button" class="admin-tab-btn <?= ($activeTab === 'dodaj') ? 'active' : '' ?>" data-tab="tab-dodaj" onclick="preklopiZavihek('dodaj')">
        Dodajanje (Uporabnik / Predmet)
    </button>
</nav>


<!-- ======================================================== -->
<!-- 1. ZAVIHEK: UPORABNIKI                                   -->
<!-- ======================================================== -->
<div id="tab-uporabniki" class="tab-content <?= ($activeTab === 'uporabniki') ? 'active' : '' ?>">

    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-uporabniki-tabela', this)">
            <h2>
                <span>Uporabniki šolskega sistema</span>
                <span class="tab-badge" id="uporabniki-counter"><?= count($users) ?> uporabnikov</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-uporabniki-tabela" class="card-body">

            <!-- FILTER ZA UPORABNIKE -->
            <div class="filter-box">
                <label>
                    Filter po vlogi
                    <select id="user_filter_vloga" onchange="filtrirajUporabnike()">
                        <option value="">Vse vloge (<?= count($users) ?>)</option>
                        <option value="dijak" <?= count($dijaki) > 0 ? "selected" : "" ?>>Samo dijaki (<?= count($dijaki) ?>)</option>
                        <option value="ucitelj">Samo učitelji (<?= count($ucitelji) ?>)</option>
                        <option value="admin">Administratorji</option>
                    </select>
                </label>

                <label id="user_filter_oddelek_label">
                    Filter dijakov po razredu / oddelku
                    <select id="user_filter_oddelek" onchange="filtrirajUporabnike()">
                        <option value="">-- Vsi razredi --</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= e($o["ime"]) ?>">
                                <?= e($o["ime"]) ?> (<?= e($o["program"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="flex-grow: 1; min-width: 200px;">
                    Hitro iskanje
                    <input type="text" id="user_filter_search" placeholder="Vpiši ime, priimek ali e-pošto..." oninput="filtrirajUporabnike()">
                </label>

                <button type="button" class="btn small" style="background: #e2e8f0; color: #334155;" onclick="ponastaviFilterUporabnikov()">
                    Počisti filter
                </button>
            </div>

            <div style="overflow-x: auto;">
                <table id="tabela-uporabniki">
                    <thead>
                        <tr>
                            <th>Ime</th>
                            <th>Priimek</th>
                            <th>E-pošta</th>
                            <th>Vloga</th>
                            <th>Razred / Oddelek</th>
                            <th>Novo geslo</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr class="user-row" 
                            data-vloga="<?= e($u["vloga"]) ?>" 
                            data-oddelek="<?= e($u["oddelek_ime"] ?? "") ?>" 
                            data-search="<?= strtolower(e($u["ime"] . " " . $u["priimek"] . " " . $u["e_posta"])) ?>">
                            <form method="post">
                                <input type="hidden" name="action" value="uredi_uporabnika">
                                <input type="hidden" name="id" value="<?= $u["id_uporabnik"] ?>">
                                <td><input name="ime" value="<?= e($u["ime"]) ?>" required></td>
                                <td><input name="priimek" value="<?= e($u["priimek"]) ?>" required></td>
                                <td><input type="email" name="e_posta" value="<?= e($u["e_posta"]) ?>" required></td>
                                <td>
                                    <select name="vloga">
                                        <?php foreach (["admin", "ucitelj", "dijak"] as $r): ?>
                                            <option value="<?= $r ?>" <?= ($u["vloga"] === $r) ? "selected" : "" ?>><?= $r ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($u["vloga"] === "dijak"): ?>
                                        <select name="oddelek_id">
                                            <option value="">Brez oddelka</option>
                                            <?php foreach ($oddelki as $o): ?>
                                                <option value="<?= $o["id_oddelek"] ?>" <?= ($u["oddelek_id"] == $o["id_oddelek"]) ? "selected" : "" ?>>
                                                    <?= e($o["ime"]) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><input type="password" name="geslo" placeholder="Ne spremeni"></td>
                                <td><button class="btn small">Shrani</button></td>
                            </form>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="izbrisi_uporabnika">
                                    <input type="hidden" name="id" value="<?= $u["id_uporabnik"] ?>">
                                    <button class="btn red small" onclick="return confirm('Izbrišem uporabnika in povezane zapise?')">Izbriši</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p id="uporabniki-no-results" style="display: none; padding: 20px; text-align: center; color: var(--muted);">
                Ni uporabnikov, ki bi ustrezali izbranim filtrom.
            </p>

        </div>
    </div>

</div>


<!-- ======================================================== -->
<!-- 2. ZAVIHEK: DIJAK -> PREDMET (NAJPREJ RAZRED, NATO DIJAK) -->
<!-- ======================================================== -->
<div id="tab-dijaki-predmeti" class="tab-content <?= ($activeTab === 'dijaki-predmeti') ? 'active' : '' ?>">

    <!-- OBRAZEC ZA POVEZAVO: DVOSTOPENJSKI VNOS (1. RAZRED, 2. DIJAK, 3. PREDMET) -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-povezi-dijaka', this)">
            <h2>
                <span>Poveži dijaka s predmetom</span>
                <small style="color: var(--muted); font-weight: normal; font-size: 13px;">(Najprej izberi razred, nato dijaka)</small>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-povezi-dijaka" class="card-body">

            <div class="student-highlight-box">
                <strong>Navodilo:</strong> Za enostavno dodajanje najprej v 1. koraku izberite razred/oddelek. Spustni seznam dijakov se bo takoj prilagodil in prikazal le dijake tistega razreda.
            </div>

            <form method="post" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                <input type="hidden" name="action" value="povezi_dijaka">

                <!-- 1. KORAK: RAZRED / ODDELEK -->
                <label style="display: grid; gap: 6px; font-size: 13px; font-weight: 700; min-width: 240px;">
                    <span><span class="step-number">1</span> Izberi razred / oddelek</span>
                    <select id="povezi_oddelek_select" onchange="posodobiDijakeObrazec(this.value)" style="min-height: 40px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;">
                        <option value="">-- Izberite razred / oddelek --</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= $o["id_oddelek"] ?>">
                                <?= e($o["ime"]) ?> (<?= e($o["program"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- 2. KORAK: DIJAK (OMOGOČEN ŠELE PO IZBIRI RAZREDA) -->
                <label style="display: grid; gap: 6px; font-size: 13px; font-weight: 700; min-width: 260px;">
                    <span><span class="step-number">2</span> Izberi dijaka</span>
                    <select name="dijak_id" id="povezi_dijak_select" disabled required style="min-height: 40px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;">
                        <option value="">-- Najprej izberite razred zgoraj --</option>
                    </select>
                </label>

                <!-- 3. KORAK: PREDMET -->
                <label style="display: grid; gap: 6px; font-size: 13px; font-weight: 700; min-width: 250px;">
                    <span><span class="step-number">3</span> Izberi predmet</span>
                    <select name="predmet_id" required style="min-height: 40px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;">
                        <option value="">-- Izberite predmet --</option>
                        <?php foreach ($predmeti as $p): ?>
                            <option value="<?= $p["id_predmet"] ?>"><?= e($p["naziv"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="btn blue" style="min-height: 40px; padding: 0 20px;">
                    Poveži dijaka s predmetom
                </button>
            </form>

        </div>
    </div>

    <!-- SEZNAM OBSTOJEČIH POVEZAV DIJAK -> PREDMET S FILTROM PO RAZREDU -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-seznam-dp', this)">
            <h2>
                <span>Seznam povezav dijakov s predmeti</span>
                <span class="tab-badge" id="dp-counter"><?= count($dp) ?> povezav</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-seznam-dp" class="card-body">

            <!-- FILTER ZA PREGLED POVEZAV PO RAZREDU -->
            <div class="filter-box">
                <label>
                    Filtriraj povezave po razredu
                    <select id="filter_dp_oddelek" onchange="filtrirajPovezaveDijakov()">
                        <option value="">Vsi razredi (<?= count($dp) ?>)</option>
                        <?php foreach ($oddelki as $o): ?>
                            <option value="<?= e($o["ime"]) ?>">
                                <?= e($o["ime"]) ?> (<?= e($o["program"]) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="flex-grow: 1; min-width: 220px;">
                    Hitro iskanje povezave
                    <input type="text" id="filter_dp_search" placeholder="Iskanje po priimku dijaka ali nazivu predmeta..." oninput="filtrirajPovezaveDijakov()">
                </label>

                <button type="button" class="btn small" style="background: #e2e8f0; color: #334155;" onclick="ponastaviFilterDP()">
                    Počisti
                </button>
            </div>

            <div id="dp-container" style="max-height: 520px; overflow-y: auto; padding-right: 5px;">
                <?php foreach ($dp as $x): ?>
                <div class="list-row dp-row" 
                     data-oddelek="<?= e($x["oddelek_ime"] ?? "") ?>" 
                     data-search="<?= strtolower(e($x["priimek"] . " " . $x["ime"] . " " . ($x["oddelek_ime"] ?? "") . " " . $x["naziv"])) ?>">
                    <div>
                        <strong><?= e($x["priimek"] . " " . $x["ime"]) ?></strong>
                        <?php if (!empty($x["oddelek_ime"])): ?>
                            <span class="badge-ok" style="font-size: 11px; margin-left: 6px;"><?= e($x["oddelek_ime"]) ?></span>
                        <?php endif; ?>
                        <span style="color: var(--muted); margin: 0 8px;">&rarr;</span>
                        <span style="color: var(--blue-dark); font-weight: 600;"><?= e($x["naziv"]) ?></span>
                    </div>

                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="action" value="odstrani_dijaka">
                        <input type="hidden" name="dijak_id" value="<?= $x["id_uporabnik"] ?>">
                        <input type="hidden" name="predmet_id" value="<?= $x["id_predmet"] ?>">
                        <button class="btn red small" onclick="return confirm('Ali res želite odstraniti dijaka s tega predmeta?')">Odstrani</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <p id="dp-no-results" style="display: none; padding: 20px; text-align: center; color: var(--muted);">
                Ni povezav, ki bi ustrezale izbranemu filtru.
            </p>

        </div>
    </div>

</div>


<!-- ======================================================== -->
<!-- 3. ZAVIHEK: UČITELJ -> PREDMET                           -->
<!-- ======================================================== -->
<div id="tab-ucitelji-predmeti" class="tab-content <?= ($activeTab === 'ucitelji-predmeti') ? 'active' : '' ?>">

    <!-- OBRAZEC ZA POVEZAVO UČITELJA S PREDMETOM -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-povezi-ucitelja', this)">
            <h2>
                <span>Dodeljevanje predmetov učiteljem</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-povezi-ucitelja" class="card-body">
            <form method="post" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                <input type="hidden" name="action" value="povezi_ucitelja">

                <label style="display: grid; gap: 6px; font-size: 13px; font-weight: 700; min-width: 260px;">
                    Izberi učitelja
                    <select name="ucitelj_id" required style="min-height: 40px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;">
                        <option value="">-- Izberite učitelja --</option>
                        <?php foreach ($ucitelji as $u): ?>
                            <option value="<?= $u["id_uporabnik"] ?>">
                                <?= e($u["priimek"] . " " . $u["ime"]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="display: grid; gap: 6px; font-size: 13px; font-weight: 700; min-width: 260px;">
                    Izberi predmet
                    <select name="predmet_id" required style="min-height: 40px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;">
                        <option value="">-- Izberite predmet --</option>
                        <?php foreach ($predmeti as $p): ?>
                            <option value="<?= $p["id_predmet"] ?>"><?= e($p["naziv"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="btn blue" style="min-height: 40px; padding: 0 20px;">
                    Poveži učitelja s predmetom
                </button>
            </form>
        </div>
    </div>

    <!-- SEZNAM POVEZAV UČITELJEV -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-seznam-up', this)">
            <h2>
                <span>Seznam učiteljev in njihovih predmetov</span>
                <span class="tab-badge" id="up-counter"><?= count($up) ?> povezav</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-seznam-up" class="card-body">

            <div class="filter-box">
                <label style="flex-grow: 1;">
                    Iskanje po učitelju ali predmetu
                    <input type="text" id="filter_up_search" placeholder="Vpiši ime učitelja ali naziv predmeta..." oninput="filtrirajPovezaveUciteljev()">
                </label>
                <button type="button" class="btn small" style="background: #e2e8f0; color: #334155;" onclick="ponastaviFilterUP()">
                    Počisti
                </button>
            </div>

            <div id="up-container" style="max-height: 520px; overflow-y: auto; padding-right: 5px;">
                <?php foreach ($up as $x): ?>
                <div class="list-row up-row" data-search="<?= strtolower(e($x["priimek"] . " " . $x["ime"] . " " . $x["naziv"])) ?>">
                    <div>
                        <strong><?= e($x["priimek"] . " " . $x["ime"]) ?></strong>
                        <span style="color: var(--muted); margin: 0 8px;">poučuje</span>
                        <span style="color: var(--blue-dark); font-weight: 600;"><?= e($x["naziv"]) ?></span>
                    </div>

                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="action" value="odstrani_ucitelja">
                        <input type="hidden" name="ucitelj_id" value="<?= $x["id_uporabnik"] ?>">
                        <input type="hidden" name="predmet_id" value="<?= $x["id_predmet"] ?>">
                        <button class="btn red small" onclick="return confirm('Ali res želite odstraniti učitelja s tega predmeta?')">Odstrani</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <p id="up-no-results" style="display: none; padding: 20px; text-align: center; color: var(--muted);">
                Ni povezav, ki bi ustrezale iskanju.
            </p>

        </div>
    </div>

</div>


<!-- ======================================================== -->
<!-- 4. ZAVIHEK: PREDMETI                                     -->
<!-- ======================================================== -->
<div id="tab-predmeti" class="tab-content <?= ($activeTab === 'predmeti') ? 'active' : '' ?>">

    <!-- DODAJ NOVI PREDMET -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-dodaj-predmet', this)">
            <h2>
                <span>Dodaj nov predmet</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-dodaj-predmet" class="card-body">
            <form method="post" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <input type="hidden" name="action" value="dodaj_predmet">

                <label style="display: grid; gap: 5px; font-size: 13px; font-weight: 700; min-width: 260px;">
                    Naziv predmeta
                    <input name="naziv" placeholder="npr. Matematika, Robotika..." required style="min-height: 38px;">
                </label>

                <label style="display: grid; gap: 5px; font-size: 13px; font-weight: 700; flex-grow: 1; min-width: 280px;">
                    Opis predmeta (neobvezno)
                    <input name="opis" placeholder="Kratek opis predmeta..." style="min-height: 38px;">
                </label>

                <button class="btn blue" style="min-height: 38px;">Dodaj predmet</button>
            </form>
        </div>
    </div>

    <!-- SEZNAM VSEH PREDMETOV -->
    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-seznam-predmetov', this)">
            <h2>
                <span>Seznam vseh šolskih predmetov</span>
                <span class="tab-badge" id="predmeti-counter"><?= count($predmeti) ?> predmetov</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-seznam-predmetov" class="card-body">

            <div class="filter-box">
                <label style="flex-grow: 1;">
                    Hitro iskanje predmeta
                    <input type="text" id="filter_predmeti_search" placeholder="Vpiši del naziva predmeta..." oninput="filtrirajPredmete()">
                </label>
                <button type="button" class="btn small" style="background: #e2e8f0; color: #334155;" onclick="ponastaviFilterPredmetov()">
                    Počisti
                </button>
            </div>

            <div style="max-height: 580px; overflow-y: auto;">
                <table id="tabela-predmeti">
                    <thead>
                        <tr>
                            <th style="min-width: 200px;">Naziv</th>
                            <th>Opis</th>
                            <th style="width: 80px;"></th>
                            <th style="width: 80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($predmeti as $p): ?>
                        <tr class="predmet-row" data-search="<?= strtolower(e($p["naziv"] . " " . ($p["opis"] ?? ""))) ?>">
                            <form method="post">
                                <input type="hidden" name="action" value="uredi_predmet">
                                <input type="hidden" name="id" value="<?= $p["id_predmet"] ?>">
                                <td><input name="naziv" value="<?= e($p["naziv"]) ?>" required></td>
                                <td><input name="opis" value="<?= e($p["opis"]) ?>"></td>
                                <td><button class="btn small">Shrani</button></td>
                            </form>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="izbrisi_predmet">
                                    <input type="hidden" name="id" value="<?= $p["id_predmet"] ?>">
                                    <button class="btn red small" onclick="return confirm('Izbrišem predmet in vse povezane zapise?')">Izbriši</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p id="predmeti-no-results" style="display: none; padding: 20px; text-align: center; color: var(--muted);">
                Ni predmetov, ki bi ustrezali iskanju.
            </p>

        </div>
    </div>

</div>


<!-- ======================================================== -->
<!-- 5. ZAVIHEK: ODDELKI & RAZREDNIKI                          -->
<!-- ======================================================== -->
<div id="tab-oddelki" class="tab-content <?= ($activeTab === 'oddelki') ? 'active' : '' ?>">

    <div class="collapsible-card">
        <div class="card-header-toggle" onclick="toggleCardBody('body-seznam-oddelkov', this)">
            <h2>
                <span>Pregled oddelkov in dodeljenih razrednikov (ŠC KER)</span>
                <span class="tab-badge"><?= count($oddelki) ?> oddelkov</span>
            </h2>
            <button type="button" class="card-toggle-btn">Skrij / Pokaži</button>
        </div>

        <div id="body-seznam-oddelkov" class="card-body">
            <p style="color: var(--muted); font-size: 13px; margin: 0 0 16px;">
                Tukaj lahko za vsak posamezni oddelek nastavite ali zamenjate razrednika. Samo razrednik posameznega oddelka lahko v modulu <em>Izostanki</em> opravičuje izostanke dijakov svojega oddelka.
            </p>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 90px;">Razred</th>
                            <th style="width: 80px;">Letnik</th>
                            <th>Izobraževalni program</th>
                            <th style="width: 110px;">Št. dijakov</th>
                            <th>Dodeljeni razrednik</th>
                            <th style="width: 100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($oddelki as $o): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="nastavi_razrednika">
                                <input type="hidden" name="oddelek_id" value="<?= (int)$o["id_oddelek"] ?>">

                                <td>
                                    <strong style="color: var(--blue-dark); font-size: 15px;"><?= e($o["ime"]) ?></strong>
                                </td>
                                <td><?= (int)$o["letnik"] ?>. letnik</td>
                                <td><?= e($o["program"]) ?></td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 7px; border-radius: 6px; background: #f1f5f9; font-weight: 600; font-size: 12px;">
                                        <?= (int)$o["st_dijakov"] ?> dijakov
                                    </span>
                                </td>
                                <td>
                                    <select name="razrednik_id" style="min-height: 36px; padding: 6px 10px; width: 100%; max-width: 320px;">
                                        <option value="">-- Brez razrednika --</option>
                                        <?php foreach ($ucitelji as $u): ?>
                                            <option value="<?= (int)$u["id_uporabnik"] ?>" <?= ((int)($o["razrednik_id"] ?? 0) === (int)$u["id_uporabnik"]) ? "selected" : "" ?>>
                                                <?= e($u["priimek"] . " " . $u["ime"]) ?> (<?= e($u["e_posta"]) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button class="btn blue small" style="width: 100%;">Shrani</button>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>


<!-- ======================================================== -->
<!-- 6. ZAVIHEK: HITRI VNOS (DODAJ UPORABNIKA ALI PREDMET)     -->
<!-- ======================================================== -->
<div id="tab-dodaj" class="tab-content <?= ($activeTab === 'dodaj') ? 'active' : '' ?>">

    <div class="admin-grid">

        <!-- DODAJ UPORABNIKA -->
        <section class="panel">
            <h2>Dodaj novega uporabnika</h2>
            <p style="color: var(--muted); font-size: 13px; margin-top: 0;">
                Ustvarite nov račun za dijaka ali učitelja. Pri dijakih določite razred.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="dodaj_uporabnika">

                <label>
                    Vloga uporabnika
                    <select name="vloga" id="dodaj_vloga_select" onchange="toggleDodajOddelek(this.value)" required>
                        <option value="dijak" selected>Dijak</option>
                        <option value="ucitelj">Učitelj</option>
                    </select>
                </label>

                <div id="dodaj_oddelek_wrap" style="width: 100%;">
                    <label>
                        Razred / Oddelek (za dijaka)
                        <select name="oddelek_id">
                            <option value="">-- Izberite razred / oddelek --</option>
                            <?php foreach ($oddelki as $o): ?>
                                <option value="<?= $o["id_oddelek"] ?>"><?= e($o["ime"]) ?> (<?= e($o["program"]) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <label>
                    Ime
                    <input name="ime" placeholder="Ime" required>
                </label>

                <label>
                    Priimek
                    <input name="priimek" placeholder="Priimek" required>
                </label>

                <label>
                    E-poštni naslov
                    <input type="email" name="e_posta" placeholder="e-posta@sc-celje.si" required>
                </label>

                <label>
                    Geslo
                    <input type="password" name="geslo" placeholder="Najmanj 6 znakov" minlength="6" required>
                </label>

                <button class="btn blue" style="width: 100%; margin-top: 10px;">Ustvari uporabnika</button>
            </form>
        </section>

        <!-- DODAJ PREDMET -->
        <section class="panel">
            <h2>Dodaj nov predmet</h2>
            <p style="color: var(--muted); font-size: 13px; margin-top: 0;">
                Dodajte nov učni predmet v bazo šole.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="dodaj_predmet">

                <label>
                    Naziv predmeta
                    <input name="naziv" placeholder="npr. Računalniški sistemi" required>
                </label>

                <label>
                    Opis predmeta
                    <textarea name="opis" placeholder="Kratek opis predmeta..." style="min-height: 100px;"></textarea>
                </label>

                <button class="btn blue" style="width: 100%; margin-top: 10px;">Ustvari predmet</button>
            </form>
        </section>

    </div>

</div>


<!-- ======================================================== -->
<!-- JAVASCRIPT LOGIKA ZA INTERAKTIVNOST IN PREGLEDNOST       -->
<!-- ======================================================== -->
<script>
// Podatki o vseh dijakih za hitro dinamično filtriranje
const vsiDijaki = <?= json_encode(array_map(function($d) {
    return [
        'id' => (int)$d['id_uporabnik'],
        'ime' => $d['ime'],
        'priimek' => $d['priimek'],
        'oddelek_id' => (int)$d['oddelek_id'],
        'oddelek_ime' => $d['oddelek_ime'] ?? ''
    ];
}, $dijaki), JSON_UNESCAPED_UNICODE) ?>;

let vsiZavihkiPrikazani = false;

// 1. PREKLAPLJANJE ZAVIHKOV
function preklopiZavihek(tabName) {
    vsiZavihkiPrikazani = false;
    document.getElementById('toggle-all-tabs-btn').textContent = "Prikaži vse zavihke";

    // Posodobi gumbke v navigaciji
    document.querySelectorAll('.admin-tab-btn').forEach(btn => {
        if (btn.getAttribute('data-tab') === 'tab-' + tabName) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    // Skrij/prikaži vsebino zavihkov
    document.querySelectorAll('.tab-content').forEach(content => {
        if (content.id === 'tab-' + tabName) {
            content.classList.add('active');
            content.style.display = 'block';
        } else {
            content.classList.remove('active');
            content.style.display = 'none';
        }
    });

    // Posodobi URL hash brez skoka
    history.replaceState(null, null, '?tab=' + encodeURIComponent(tabName));
}

// 2. PRIKAŽI VSE ZAVIHKI HKRATI / POGLED PO ZAVIHKIH
function togglePrikaziVse() {
    vsiZavihkiPrikazani = !vsiZavihkiPrikazani;
    const btn = document.getElementById('toggle-all-tabs-btn');

    if (vsiZavihkiPrikazani) {
        btn.textContent = "Pogled z zavihki";
        document.querySelectorAll('.tab-content').forEach(c => {
            c.style.display = 'block';
        });
        document.querySelectorAll('.admin-tab-btn').forEach(b => b.classList.remove('active'));
    } else {
        btn.textContent = "Prikaži vse zavihke";
        preklopiZavihek('uporabniki');
    }
}

// 3. SKRIJ / POKAŽI VSEBINO KARTICE (COLLAPSIBLE)
function toggleCardBody(bodyId, headerElem) {
    const body = document.getElementById(bodyId);
    if (!body) return;
    const isHidden = body.classList.toggle('hidden');
    const toggleBtn = headerElem.querySelector('.card-toggle-btn');
    if (toggleBtn) {
        toggleBtn.textContent = isHidden ? "Prikaži vsebino" : "Skrij vsebino";
    }
}

// 4. KORAK ZA DIJAKA: OB IZBIRI RAZREDA POSODOBI SEZNAM DIJAKOV
function posodobiDijakeObrazec(oddelekId) {
    const sel = document.getElementById('povezi_dijak_select');
    sel.innerHTML = '';

    if (!oddelekId) {
        sel.disabled = true;
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '-- Najprej izberite razred zgoraj --';
        sel.appendChild(opt);
        return;
    }

    const filtrirani = vsiDijaki.filter(d => String(d.oddelek_id) === String(oddelekId));

    if (filtrirani.length === 0) {
        sel.disabled = true;
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '-- V tem razredu ni vpisanih dijakov --';
        sel.appendChild(opt);
        return;
    }

    sel.disabled = false;
    const defOpt = document.createElement('option');
    defOpt.value = '';
    defOpt.textContent = '-- Izberite dijaka --';
    sel.appendChild(defOpt);

    // Razvrsti po abecedi
    filtrirani.sort((a, b) => (a.priimek + ' ' + a.ime).localeCompare(b.priimek + ' ' + b.ime, 'sl'));

    filtrirani.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.priimek + ' ' + d.ime;
        sel.appendChild(opt);
    });
}

// 5. FILTRIRANJE SEZNAMA POVEZAV DIJAK -> PREDMET
function filtrirajPovezaveDijakov() {
    const oddelek = document.getElementById('filter_dp_oddelek').value.trim();
    const iskanje = document.getElementById('filter_dp_search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.dp-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const rOdd = r.getAttribute('data-oddelek') || '';
        const rText = r.getAttribute('data-search') || '';

        const matchOdd = (!oddelek || rOdd === oddelek);
        const matchSearch = (!iskanje || rText.includes(iskanje));

        if (matchOdd && matchSearch) {
            r.style.display = 'flex';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    document.getElementById('dp-counter').textContent = visibleCount + ' povezav';
    document.getElementById('dp-no-results').style.display = (visibleCount === 0) ? 'block' : 'none';
}

function ponastaviFilterDP() {
    document.getElementById('filter_dp_oddelek').value = '';
    document.getElementById('filter_dp_search').value = '';
    filtrirajPovezaveDijakov();
}

// 6. FILTRIRANJE UPORABNIKOV
function filtrirajUporabnike() {
    const vloga = document.getElementById('user_filter_vloga').value;
    const oddelek = document.getElementById('user_filter_oddelek').value.trim();
    const iskanje = document.getElementById('user_filter_search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.user-row');
    let visibleCount = 0;

    // Skrij/prikaži oddelek filter glede na vlogo
    const oddelekLabel = document.getElementById('user_filter_oddelek_label');
    if (vloga === 'ucitelj' || vloga === 'admin') {
        oddelekLabel.style.display = 'none';
    } else {
        oddelekLabel.style.display = 'grid';
    }

    rows.forEach(r => {
        const rVloga = r.getAttribute('data-vloga') || '';
        const rOdd = r.getAttribute('data-oddelek') || '';
        const rSearch = r.getAttribute('data-search') || '';

        const matchVloga = (!vloga || rVloga === vloga);
        const matchOdd = (!oddelek || rOdd === oddelek);
        const matchSearch = (!iskanje || rSearch.includes(iskanje));

        if (matchVloga && matchOdd && matchSearch) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    document.getElementById('uporabniki-counter').textContent = visibleCount + ' uporabnikov';
    document.getElementById('uporabniki-no-results').style.display = (visibleCount === 0) ? 'block' : 'none';
}

function ponastaviFilterUporabnikov() {
    document.getElementById('user_filter_vloga').value = '';
    document.getElementById('user_filter_oddelek').value = '';
    document.getElementById('user_filter_search').value = '';
    filtrirajUporabnike();
}

// 7. FILTRIRANJE POVEZAV UČITELJEV
function filtrirajPovezaveUciteljev() {
    const iskanje = document.getElementById('filter_up_search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.up-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const rSearch = r.getAttribute('data-search') || '';
        if (!iskanje || rSearch.includes(iskanje)) {
            r.style.display = 'flex';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    document.getElementById('up-counter').textContent = visibleCount + ' povezav';
    document.getElementById('up-no-results').style.display = (visibleCount === 0) ? 'block' : 'none';
}

function ponastaviFilterUP() {
    document.getElementById('filter_up_search').value = '';
    filtrirajPovezaveUciteljev();
}

// 8. FILTRIRANJE PREDMETOV
function filtrirajPredmete() {
    const iskanje = document.getElementById('filter_predmeti_search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.predmet-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const rSearch = r.getAttribute('data-search') || '';
        if (!iskanje || rSearch.includes(iskanje)) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    document.getElementById('predmeti-counter').textContent = visibleCount + ' predmetov';
    document.getElementById('predmeti-no-results').style.display = (visibleCount === 0) ? 'block' : 'none';
}

function ponastaviFilterPredmetov() {
    document.getElementById('filter_predmeti_search').value = '';
    filtrirajPredmete();
}

// 9. PREKLOP VNOSA ODDELEK PRI DODAJANJU
function toggleDodajOddelek(vloga) {
    const wrap = document.getElementById('dodaj_oddelek_wrap');
    if (vloga === 'dijak') {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
}

// Zagon ob nalaganju strani
document.addEventListener('DOMContentLoaded', function() {
    // Inicialni filter uporabnikov
    filtrirajUporabnike();
});
</script>

<?php pageEnd(); ?>
