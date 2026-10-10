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
// AKCIJE ADMINISTRATORJA
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireRole("admin");

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

        } else {

            throw new RuntimeException();
        }

        flash("Urnik je bil posodobljen.");

    } catch (Throwable $e) {

        flash("Napaka pri urejanju urnika.");
    }

    redirect("urnik.php");
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

if (!$entries) {
    $minUra = 1;
}
?>

<div class="schedule-table-wrap">
<table class="schedule-table">

    <thead>
        <tr>
            <th>Ura</th>
            <?php foreach ($days as $day): ?>
                <th><?= e($day) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>

    <tbody>

    <?php if (!$entries): ?>

        <tr>
            <td colspan="<?= count($days) + 1 ?>">Ni vnešenih ur.</td>
        </tr>

    <?php else: ?>

        <?php for ($ura = $minUra; $ura <= $maxUra; $ura++): ?>

            <tr>
                <th><?= $ura ?>. ura</th>

                <?php foreach ($days as $day): ?>

                    <td>

                    <?php foreach ($byCell[$ura][$day] ?? [] as $entry): ?>

                        <div class="schedule-cell">

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

                            <?php if ($user["vloga"] === "admin"): ?>

                                <details>

                                    <summary>Uredi</summary>

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

                                        <button class="btn small">Shrani</button>

                                    </form>

                                    <form method="post">

                                        <input type="hidden" name="action" value="izbrisi">
                                        <input type="hidden" name="id" value="<?= $entry["id_urnik"] ?>">

                                        <button
                                            class="btn red small"
                                            onclick="return confirm('Izbrišem uro?')"
                                        >
                                            Izbriši
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
