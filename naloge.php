<?php

require_once "funkcije.php";

requireLogin();

$user = getUser();

if ($user["vloga"] === "admin") {
    redirect("nadzorna_plosca.php");
}

if ($user["vloga"] !== "dijak") {
    redirect("nadzorna_plosca.php");
}


// =========================================================================
// 1. AKCIJE (POST)
// =========================================================================

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {

    $action = $_POST["action"] ?? "";

    // --------------------------------------------------
    // AKCIJE ZA DIJAKA
    // --------------------------------------------------

        // ODDAJA NALOGE
        if ($action === "oddaj_nalogo") {

            $nalogaId = (int) ($_POST["naloga_id"] ?? 0);
            $studentOddelekId = (int) ($user["oddelek_id"] ?? 0);

            // Preverimo, ali dijak obiskuje predmet in je naloga namenjena njegovemu oddelku
            $stmt = $conn->prepare(
                "SELECT n.*, p.naziv AS predmet_naziv
                 FROM Naloga n
                 JOIN Dijak_Predmet dp ON dp.predmet_id = n.predmet_id
                 JOIN Predmet p ON p.id_predmet = n.predmet_id
                 WHERE n.id_naloga = ?
                   AND dp.dijak_id = ?
                   AND (
                       NOT EXISTS (SELECT 1 FROM Naloga_Oddelek no1 WHERE no1.naloga_id = n.id_naloga)
                       OR EXISTS (
                           SELECT 1 FROM Naloga_Oddelek no2
                           WHERE no2.naloga_id = n.id_naloga
                             AND no2.oddelek_id = ?
                       )
                   )"
            );
            $stmt->execute([$nalogaId, $user["id_uporabnik"], $studentOddelekId]);
            $naloga = $stmt->fetch();

            if (!$naloga) {
                flash("Te naloge ne morete oddati ali ni namenjena vašemu razredu.");
                redirect("naloge.php");
            }

            if (!uploadIsValid($_FILES["datoteka"] ?? [])) {
                flash("Datoteka ni veljavna ali je večja od 40 MB.");
                redirect("naloge.php");
            }

            $original = $_FILES["datoteka"]["name"];
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            if ($extension === "" || !preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
                flash("Datoteka nima veljavne končnice.");
                redirect("naloge.php");
            }

            if (in_array($extension, ["php", "phtml", "phar", "php3", "php4", "php5", "php7", "phps"], true)) {
                flash("Nalaganje PHP datotek ni dovoljeno.");
                redirect("naloge.php");
            }

            $filename = safeFileName(
                $user["priimek"] . " " . $user["ime"] . " – " . $naloga["naslov"]
            );
            if ($extension !== "") {
                $filename .= "." . $extension;
            }

            $relativePath = "uploads/naloge/" . $filename;
            $fullPath = __DIR__ . "/" . $relativePath;
            ensureUploadDirectory("uploads/naloge");

            $stmt = $conn->prepare(
                "SELECT id_oddana, datoteka_pot FROM Oddana_Naloga WHERE naloga_id = ? AND dijak_id = ?"
            );
            $stmt->execute([$nalogaId, $user["id_uporabnik"]]);
            $stara = $stmt->fetch();

            if ($stara && empty($_POST["potrdi_povoz"])) {
                $tmpDir = __DIR__ . "/uploads/naloge/tmp_" . $user["id_uporabnik"];
                if (!is_dir($tmpDir)) {
                    mkdir($tmpDir, 0755, true);
                }
                $tmpPath = $tmpDir . "/" . $filename;

                if (!move_uploaded_file($_FILES["datoteka"]["tmp_name"], $tmpPath)) {
                    flash("Datoteke ni bilo mogoče shraniti.");
                    redirect("naloge.php");
                }

                $_SESSION["potrditev"] = [
                    "naloga_id"     => $nalogaId,
                    "tmp_path"      => $tmpPath,
                    "relative_path" => $relativePath,
                    "full_path"     => $fullPath,
                    "komentar"      => trim($_POST["komentar"] ?? ""),
                    "stara_id"      => $stara["id_oddana"],
                    "sporocilo"     => "Za nalogo »" . $naloga["naslov"] . "« že obstaja oddaja. Ali jo želiš povoziti z novo datoteko?"
                ];

                redirect("naloge.php");
            }

            if ($stara && !empty($_POST["potrdi_povoz"])) {
                $potrditev = $_SESSION["potrditev"] ?? null;

                if ($potrditev && (int)$potrditev["naloga_id"] === $nalogaId && file_exists($potrditev["tmp_path"])) {
                    if (file_exists($potrditev["full_path"])) {
                        unlink($potrditev["full_path"]);
                    }
                    rename($potrditev["tmp_path"], $potrditev["full_path"]);

                    $stmt = $conn->prepare(
                        "UPDATE Oddana_Naloga
                         SET datoteka_pot = ?,
                             komentar_dijaka = ?,
                             oddano_ob = NOW()
                         WHERE id_oddana = ?"
                    );
                    $stmt->execute([
                        $potrditev["relative_path"],
                        $potrditev["komentar"],
                        $potrditev["stara_id"]
                    ]);

                    $tmpDir = dirname($potrditev["tmp_path"]);
                    if (is_dir($tmpDir)) {
                        @rmdir($tmpDir);
                    }

                    unset($_SESSION["potrditev"]);
                    flash("Naloga je bila uspešno ponovno oddana (prejšnja datoteka je bila povozena).");
                    redirect("naloge.php");
                } else {
                    if (!move_uploaded_file($_FILES["datoteka"]["tmp_name"], $fullPath)) {
                        flash("Datoteke ni bilo mogoče shraniti.");
                        redirect("naloge.php");
                    }

                    $stmt = $conn->prepare(
                        "UPDATE Oddana_Naloga
                         SET datoteka_pot = ?,
                             komentar_dijaka = ?,
                             oddano_ob = NOW()
                         WHERE id_oddana = ?"
                    );
                    $stmt->execute([
                        $relativePath,
                        trim($_POST["komentar"] ?? ""),
                        $stara["id_oddana"]
                    ]);

                    unset($_SESSION["potrditev"]);
                    flash("Naloga je bila uspešno ponovno oddana.");
                    redirect("naloge.php");
                }
            }

            if (!move_uploaded_file($_FILES["datoteka"]["tmp_name"], $fullPath)) {
                flash("Datoteke ni bilo mogoče shraniti.");
                redirect("naloge.php");
            }

            $stmt = $conn->prepare(
                "INSERT INTO Oddana_Naloga (naloga_id, dijak_id, datoteka_pot, komentar_dijaka)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $nalogaId,
                $user["id_uporabnik"],
                $relativePath,
                trim($_POST["komentar"] ?? "")
            ]);

            unset($_SESSION["potrditev"]);
            flash("Naloga »" . $naloga["naslov"] . "« je bila uspešno oddana.");
            redirect("naloge.php");
        }

        // POTRDI POVOZ
        if ($action === "potrdi_povoz") {
            $potrditev = $_SESSION["potrditev"] ?? null;

            if ($potrditev && file_exists($potrditev["tmp_path"])) {
                if (file_exists($potrditev["full_path"])) {
                    unlink($potrditev["full_path"]);
                }
                rename($potrditev["tmp_path"], $potrditev["full_path"]);

                $stmt = $conn->prepare(
                    "UPDATE Oddana_Naloga
                     SET datoteka_pot = ?,
                         komentar_dijaka = ?,
                         oddano_ob = NOW()
                     WHERE id_oddana = ?"
                );
                $stmt->execute([
                    $potrditev["relative_path"],
                    $potrditev["komentar"],
                    $potrditev["stara_id"]
                ]);

                $tmpDir = dirname($potrditev["tmp_path"]);
                if (is_dir($tmpDir)) {
                    @rmdir($tmpDir);
                }

                unset($_SESSION["potrditev"]);
                flash("Naloga je bila uspešno ponovno oddana.");
            } else {
                unset($_SESSION["potrditev"]);
                flash("Začasna datoteka je potekla. Prosimo, ponovno oddajte nalogo.");
            }
            redirect("naloge.php");
        }

        // PREKLIČI POVOZ
        if ($action === "preklici_povoz") {
            $potrditev = $_SESSION["potrditev"] ?? null;

            if ($potrditev && isset($potrditev["tmp_path"]) && file_exists($potrditev["tmp_path"])) {
                unlink($potrditev["tmp_path"]);
                $tmpDir = dirname($potrditev["tmp_path"]);
                if (is_dir($tmpDir)) {
                    @rmdir($tmpDir);
                }
            }

            unset($_SESSION["potrditev"]);
            flash("Ponovna oddaja je bila preklicana.");
            redirect("naloge.php");
        }

}


// =========================================================================
// 2. PODATKI IN PRIKAZ ZA DIJAKA
// =========================================================================

    $studentOddelekId = (int) ($user["oddelek_id"] ?? 0);

    // Vsi predmeti, ki jih dijak obiskuje
    $stmt = $conn->prepare(
        "SELECT p.id_predmet, p.naziv,
                GROUP_CONCAT(DISTINCT CONCAT(u.ime, ' ', u.priimek) ORDER BY u.priimek SEPARATOR ', ') AS ucitelji
         FROM Predmet p
         JOIN Dijak_Predmet dp ON dp.predmet_id = p.id_predmet
         LEFT JOIN Ucitelj_Predmet up ON up.predmet_id = p.id_predmet
         LEFT JOIN Uporabnik u ON u.id_uporabnik = up.ucitelj_id
         WHERE dp.dijak_id = ? AND LOWER(p.naziv) <> 'razredna ura'
         GROUP BY p.id_predmet
         ORDER BY p.naziv"
    );
    $stmt->execute([$user["id_uporabnik"]]);
    $mojiPredmeti = $stmt->fetchAll();

    // Vse naloge za vpisane predmete (omejene na dijakov oddelek ali za vse oddelke)
    $stmt = $conn->prepare(
        "SELECT n.*, p.naziv AS predmet_naziv,
                u.ime AS ucitelj_ime, u.priimek AS ucitelj_priimek,
                GROUP_CONCAT(o.ime ORDER BY o.ime SEPARATOR ', ') AS oddelki_imena
         FROM Naloga n
         JOIN Predmet p ON p.id_predmet = n.predmet_id
         JOIN Dijak_Predmet dp ON dp.predmet_id = p.id_predmet
         LEFT JOIN Uporabnik u ON u.id_uporabnik = n.ucitelj_id
         LEFT JOIN Naloga_Oddelek no_rel ON no_rel.naloga_id = n.id_naloga
         LEFT JOIN Oddelek o ON o.id_oddelek = no_rel.oddelek_id
         WHERE dp.dijak_id = ?
           AND (
               NOT EXISTS (SELECT 1 FROM Naloga_Oddelek no1 WHERE no1.naloga_id = n.id_naloga)
               OR EXISTS (
                   SELECT 1 FROM Naloga_Oddelek no2
                   WHERE no2.naloga_id = n.id_naloga
                     AND no2.oddelek_id = ?
               )
           )
         GROUP BY n.id_naloga
         ORDER BY n.rok_oddaje ASC"
    );
    $stmt->execute([$user["id_uporabnik"], $studentOddelekId]);
    $vseNaloge = $stmt->fetchAll();

    // Vse oddaje trenutnega dijaka
    $stmt = $conn->prepare("SELECT * FROM Oddana_Naloga WHERE dijak_id = ?");
    $stmt->execute([$user["id_uporabnik"]]);
    $oddajeRaw = $stmt->fetchAll();

    $oddajeMap = [];
    foreach ($oddajeRaw as $od) {
        $oddajeMap[$od["naloga_id"]] = $od;
    }

    $nalogePoPredmetih = [];
    foreach ($mojiPredmeti as $pred) {
        $nalogePoPredmetih[$pred["id_predmet"]] = [];
    }
    foreach ($vseNaloge as $nal) {
        $nalogePoPredmetih[$nal["predmet_id"]][] = $nal;
    }

    $stPredmetov = count($mojiPredmeti);
    $stNalog     = count($vseNaloge);
    $stOddanih   = 0;
    foreach ($vseNaloge as $n) {
        if (isset($oddajeMap[$n["id_naloga"]])) {
            $stOddanih++;
        }
    }
    $stCaka = $stNalog - $stOddanih;

    pageStart("Naloge po predmetih");
    $potrditev = $_SESSION["potrditev"] ?? null;
    ?>

    <h1>Naloge po predmetih</h1>
    <p class="panel-subtitle mb-4">
        Pregled nalog in zadolžitev, organiziranih po vseh vaših vpisanih predmetih.
    </p>

    <?php if ($potrditev): ?>
    <div class="confirm-box mb-4">
        <strong><?= e($potrditev["sporocilo"]) ?></strong>
        <div class="d-flex gap-2 mt-2">
            <form method="POST">
                <input type="hidden" name="action" value="potrdi_povoz">
                <button class="btn blue">Da, povozi oddajo</button>
            </form>
            <form method="POST">
                <input type="hidden" name="action" value="preklici_povoz">
                <button class="btn btn-secondary small">Prekliči</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- STATISTIKA DIJAKA -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $stPredmetov ?></div>
            <div class="stat-label">Vpisani predmeti</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stNalog ?></div>
            <div class="stat-label">Vseh razpisanih nalog</div>
        </div>
        <div class="stat-card">
            <div class="stat-num text-success"><?= $stOddanih ?></div>
            <div class="stat-label">Oddanih nalog</div>
        </div>
        <div class="stat-card">
            <div class="stat-num <?= $stCaka > 0 ? 'text-warning' : 'text-muted' ?>"><?= $stCaka ?></div>
            <div class="stat-label">Čaka na oddajo</div>
        </div>
    </div>

    <!-- FILTER PO PREDMETIH (Zložljiv iskalni meni) -->
    <div class="subject-filter-bar">
        <div class="subject-filter-quick">
            <span class="filter-bar-label">Prikaz:</span>
            <button type="button" id="btn-dijak-vsi" class="filter-pill active" onclick="filtrirajPredmet('vsi', this, 'Vsi predmeti')">
                Vsi predmeti <span class="filter-count"><?= $stNalog ?></span>
            </button>
            <button type="button" id="btn-dijak-aktivne" class="filter-pill" onclick="filtrirajPredmet('samo_aktivne', this, 'Samo z nalogami')">
                Samo z nalogami <span class="filter-count"><?= count(array_filter($nalogePoPredmetih, fn($arr) => count($arr) > 0)) ?></span>
            </button>
        </div>

        <div class="subject-combobox" id="dijak-subject-combobox">
            <div class="subject-combobox-control" onclick="odpriPredmetMeni('dijak')">
                <svg class="subject-combobox-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input
                    type="text"
                    id="dijak-subject-search"
                    class="subject-combobox-input"
                    placeholder="Izberi ali vpiši predmet ..."
                    autocomplete="off"
                    onfocus="odpriPredmetMeni('dijak', true)"
                    oninput="isciPredmetVMeniju('dijak', this.value)"
                >
                <button type="button" id="dijak-subject-clear" class="subject-combobox-clear d-none" onclick="pocistiIzbiroPredmeta(event, 'dijak')" title="Počisti izbiro">✕</button>
                <span class="subject-combobox-arrow">▾</span>
            </div>

            <div class="subject-dropdown-menu d-none" id="dijak-subject-menu">
                <div class="subject-dropdown-list" id="dijak-subject-list">
                    <button type="button" class="subject-dropdown-item active" data-id="vsi" data-name="vsi predmeti" onclick="izberiPredmetIzMenija('dijak', 'vsi', 'Vsi predmeti', this)">
                        <span class="subject-dropdown-name">Vsi predmeti</span>
                        <span class="filter-count"><?= $stNalog ?></span>
                    </button>
                    <button type="button" class="subject-dropdown-item" data-id="samo_aktivne" data-name="samo z nalogami" onclick="izberiPredmetIzMenija('dijak', 'samo_aktivne', 'Samo z nalogami', this)">
                        <span class="subject-dropdown-name">Samo predmeti z nalogami</span>
                        <span class="filter-count"><?= count(array_filter($nalogePoPredmetih, fn($arr) => count($arr) > 0)) ?></span>
                    </button>
                    <div class="subject-dropdown-divider"></div>
                    <?php foreach ($mojiPredmeti as $p): ?>
                        <?php $count = count($nalogePoPredmetih[$p["id_predmet"]]); ?>
                        <button
                            type="button"
                            class="subject-dropdown-item"
                            data-id="<?= (int)$p['id_predmet'] ?>"
                            data-name="<?= e(mb_strtolower($p['naziv'])) ?>"
                            onclick="izberiPredmetIzMenija('dijak', '<?= (int)$p['id_predmet'] ?>', '<?= e(addslashes($p['naziv'])) ?>', this)"
                        >
                            <span class="subject-dropdown-name"><?= e($p["naziv"]) ?></span>
                            <span class="filter-count <?= $count > 0 ? 'has-items' : '' ?>"><?= $count ?></span>
                        </button>
                    <?php endforeach; ?>
                    <div class="subject-dropdown-empty d-none" id="dijak-subject-empty">
                        Ni najdenega predmeta.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="predmeti-seznam">
    <?php if (empty($mojiPredmeti)): ?>
        <div class="panel">
            <p class="m-0 text-muted">
                Trenutno nimate vpisanih predmetov. Predmete lahko dodate v zavihku
                <a href="dijak.php" class="link-bold">Moja učilnica</a>.
            </p>
        </div>
    <?php endif; ?>

    <?php foreach ($mojiPredmeti as $p): ?>
        <?php
        $pid = (int) $p["id_predmet"];
        $predmetNaloge = $nalogePoPredmetih[$pid] ?? [];
        $hasTasks = count($predmetNaloge) > 0;
        ?>

        <div class="panel subject-panel" id="predmet-panel-<?= $pid ?>" data-predmet-id="<?= $pid ?>" data-has-tasks="<?= $hasTasks ? '1' : '0' ?>">
            
            <div class="subject-panel-header">
                <div>
                    <h2 class="subject-panel-title"><?= e($p["naziv"]) ?></h2>
                    <div class="subject-panel-teacher">
                        Profesor: <?= e($p["ucitelji"] ?: "Ni dodeljenega profesorja") ?>
                    </div>
                </div>
                <div>
                    <?php if ($hasTasks): ?>
                        <span class="badge-ok"><?= count($predmetNaloge) ?> <?= count($predmetNaloge) === 1 ? 'naloga' : (count($predmetNaloge) === 2 ? 'nalogi' : 'nalog') ?></span>
                    <?php else: ?>
                        <span class="badge-neutral">Brez razpisanih nalog</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasTasks): ?>
                <div class="tasks-list">
                    <?php foreach ($predmetNaloge as $n): ?>
                        <?php
                        $nid = (int) $n["id_naloga"];
                        $oddaja = $oddajeMap[$nid] ?? null;
                        $jeOddana = ($oddaja !== null);
                        $rokTs = strtotime($n["rok_oddaje"]);
                        $zamujeno = (!$jeOddana && $rokTs < time());
                        $oddelkiLabel = !empty($n["oddelki_imena"]) ? $n["oddelki_imena"] : "Vsi razredi";
                        ?>

                        <div class="task-card">
                            <div class="task-card-header">
                                <div>
                                    <h3 class="task-card-title"><?= e($n["naslov"]) ?></h3>
                                    <div class="task-meta">
                                        Objavil: <?= e(($n["ucitelj_ime"] ?? "") . " " . ($n["ucitelj_priimek"] ?? "")) ?>
                                        &bull; Razred: <strong><?= e($oddelkiLabel) ?></strong>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($jeOddana): ?>
                                        <span class="badge-ok">Oddano (<?= date("d. m. Y H:i", strtotime($oddaja["oddano_ob"])) ?>)</span>
                                    <?php elseif ($zamujeno): ?>
                                        <span class="badge-danger">Rok je potekel (<?= date("d. m. Y H:i", $rokTs) ?>)</span>
                                    <?php else: ?>
                                        <span class="badge-warn">Rok: <?= date("d. m. Y H:i", $rokTs) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($n["navodila"])): ?>
                                <div class="task-instructions"><?= e($n["navodila"]) ?></div>
                            <?php endif; ?>

                            <?php if ($jeOddana): ?>
                                <div class="submission-status-box">
                                    <div class="panel-header-actions">
                                        <div>
                                            <strong>Vaša oddana datoteka:</strong>
                                            <a href="<?= e($oddaja["datoteka_pot"]) ?>" target="_blank" class="link-bold ml-1">
                                                <?= e(basename($oddaja["datoteka_pot"])) ?>
                                            </a>
                                            <?php if (!empty($oddaja["komentar_dijaka"])): ?>
                                                <div class="text-xs text-muted mt-1">
                                                    Vaš komentar: »<?= e($oddaja["komentar_dijaka"]) ?>«
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php if ($oddaja["ocena"] !== null): ?>
                                                <span class="badge-ok text-sm py-1 px-2">
                                                    Ocena: <?= e($oddaja["ocena"]) ?>
                                                </span>
                                                <?php if (!empty($oddaja["komentar_ucitelja"])): ?>
                                                    <div class="text-xs text-muted text-right mt-1">
                                                        Komentar: <?= e($oddaja["komentar_ucitelja"]) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-xs text-muted">Še neocenjeno</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!$potrditev || (int)$potrditev["naloga_id"] !== $nid): ?>
                                <form method="POST" enctype="multipart/form-data" class="submission-form">
                                    <input type="hidden" name="action" value="oddaj_nalogo">
                                    <input type="hidden" name="naloga_id" value="<?= $nid ?>">

                                    <div class="d-flex gap-2 items-center flex-wrap">
                                        <label class="m-0 text-sm font-semibold">
                                            <?= $jeOddana ? "Izberite novo datoteko za povoz:" : "Datoteka:" ?>
                                            <input type="file" name="datoteka" required class="d-block mt-1">
                                        </label>

                                        <input type="text" name="komentar" placeholder="Neobvezen komentar ob oddaji" class="form-input-comment">

                                        <button class="btn blue btn-h36">
                                            <?= $jeOddana ? "Oddaj ponovno" : "Oddaj nalogo" ?>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="prazno-stanje">
                    Pri tem predmetu trenutno nimate razpisanih nalog.
                </div>
            <?php endif; ?>

        </div>
    <?php endforeach; ?>
    </div>

    <script>
    function filtrirajPredmet(kategorija, gumb, naziv) {
        document.querySelectorAll('.subject-filter-quick .filter-pill').forEach(b => b.classList.remove('active'));
        if (gumb) {
            gumb.classList.add('active');
        } else if (kategorija === 'vsi') {
            const bVsi = document.getElementById('btn-dijak-vsi');
            if (bVsi) bVsi.classList.add('active');
        } else if (kategorija === 'samo_aktivne') {
            const bAkt = document.getElementById('btn-dijak-aktivne');
            if (bAkt) bAkt.classList.add('active');
        }

        const input = document.getElementById('dijak-subject-search');
        const clearBtn = document.getElementById('dijak-subject-clear');
        if (input && clearBtn) {
            if (kategorija === 'vsi' || kategorija === 'samo_aktivne') {
                input.value = '';
                clearBtn.classList.add('d-none');
            } else if (naziv) {
                input.value = naziv;
                clearBtn.classList.remove('d-none');
            }
        }

        document.querySelectorAll('#dijak-subject-list .subject-dropdown-item').forEach(item => {
            item.classList.toggle('active', String(item.getAttribute('data-id')) === String(kategorija));
            item.classList.remove('d-none');
        });

        document.querySelectorAll('.subject-panel').forEach(panel => {
            const pid = panel.getAttribute('data-predmet-id');
            const hasTasks = panel.getAttribute('data-has-tasks') === '1';

            if (kategorija === 'vsi') {
                panel.style.display = '';
            } else if (kategorija === 'samo_aktivne') {
                panel.style.display = hasTasks ? '' : 'none';
            } else {
                panel.style.display = (String(pid) === String(kategorija)) ? '' : 'none';
            }
        });
    }

    function odpriPredmetMeni(tip, prisiliOdpri) {
        const menu = document.getElementById(tip + '-subject-menu');
        const box = document.getElementById(tip + '-subject-combobox');
        if (!menu || !box) return;

        if (prisiliOdpri) {
            menu.classList.remove('d-none');
            box.classList.add('open');
        } else {
            const isHidden = menu.classList.contains('d-none');
            menu.classList.toggle('d-none', !isHidden);
            box.classList.toggle('open', isHidden);
            if (isHidden) {
                const inp = document.getElementById(tip + '-subject-search');
                if (inp) inp.focus();
            }
        }
    }

    function izberiPredmetIzMenija(tip, kategorija, naziv, itemEl) {
        const menu = document.getElementById(tip + '-subject-menu');
        const box = document.getElementById(tip + '-subject-combobox');
        if (menu) menu.classList.add('d-none');
        if (box) box.classList.remove('open');

        filtrirajPredmet(kategorija, null, naziv);
    }

    function pocistiIzbiroPredmeta(e, tip) {
        e.stopPropagation();
        const inp = document.getElementById(tip + '-subject-search');
        const clearBtn = document.getElementById(tip + '-subject-clear');
        if (inp) inp.value = '';
        if (clearBtn) clearBtn.classList.add('d-none');
        isciPredmetVMeniju(tip, '');
        filtrirajPredmet('vsi', document.getElementById('btn-dijak-vsi'), 'Vsi predmeti');
    }

    function isciPredmetVMeniju(tip, query) {
        const menu = document.getElementById(tip + '-subject-menu');
        const box = document.getElementById(tip + '-subject-combobox');
        const clearBtn = document.getElementById(tip + '-subject-clear');
        if (menu && menu.classList.contains('d-none')) {
            menu.classList.remove('d-none');
            if (box) box.classList.add('open');
        }

        const q = query.trim().toLowerCase();
        if (clearBtn) {
            clearBtn.classList.toggle('d-none', q.length === 0);
        }

        let visibleCount = 0;
        document.querySelectorAll('#' + tip + '-subject-list .subject-dropdown-item').forEach(item => {
            const name = (item.getAttribute('data-name') || '').toLowerCase();
            const match = q === '' || name.includes(q);
            item.classList.toggle('d-none', !match);
            if (match) visibleCount++;
        });

        const emptyEl = document.getElementById(tip + '-subject-empty');
        if (emptyEl) {
            emptyEl.classList.toggle('d-none', visibleCount > 0);
        }

        // Hkrati sproti filtriraj prikazane predmete na strani glede na vpisano besedilo
        document.querySelectorAll('.subject-panel').forEach(panel => {
            const titleEl = panel.querySelector('.subject-panel-title');
            const titleText = titleEl ? titleEl.textContent.toLowerCase() : '';
            if (q === '' || titleText.includes(q)) {
                panel.style.display = '';
            } else {
                panel.style.display = 'none';
            }
        });
    }

    document.addEventListener('click', function(e) {
        const box = document.getElementById('dijak-subject-combobox');
        const menu = document.getElementById('dijak-subject-menu');
        if (box && menu && !box.contains(e.target)) {
            menu.classList.add('d-none');
            box.classList.remove('open');
        }
    });
    </script>

    <?php pageEnd(); ?>
