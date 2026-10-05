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
                trim($_POST["ucilnica"])
            ];

            // Preverjanje dneva
            if (!in_array($vrednosti[2], $days, true)) {
                throw new RuntimeException();
            }

            // Preverjanje ure
            if ($vrednosti[3] < 1 || $vrednosti[3] > 12) {
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
                        ucilnica
                    )
                    VALUES (?, ?, ?, ?, ?)"
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
                         ucilnica = ?
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
        u.priimek

    FROM Urnik ur

    JOIN Predmet p
        ON p.id_predmet = ur.predmet_id

    JOIN Uporabnik u
        ON u.id_uporabnik = ur.ucitelj_id
";

$params = [];


// UČITELJ
if ($user["vloga"] === "ucitelj") {

    $sql .= "
        WHERE ur.ucitelj_id = ?
    ";

    $params[] = $user["id_uporabnik"];
}


// DIJAK
elseif ($user["vloga"] === "dijak") {

    $sql .= "
        JOIN Dijak_Predmet dp
            ON dp.predmet_id = ur.predmet_id

        WHERE dp.dijak_id = ?
    ";

    $params[] = $user["id_uporabnik"];
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
        min="1"
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

    <?php
}


// ==============================
// STRAN
// ==============================

pageStart("Urnik");

?>

<h1>Urnik</h1>


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
                    "ucilnica" => ""
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

<div class="schedule-grid">

    <?php foreach ($days as $day): ?>

        <section class="panel">

            <h2>
                <?= e($day) ?>
            </h2>


            <?php

            $imaUre = false;

            foreach ($entries as $entry):

                if ($entry["dan_v_tednu"] !== $day) {
                    continue;
                }

                $imaUre = true;

            ?>

                <div class="schedule-row">

                    <strong>
                        <?= e($entry["ura"]) ?>. ura
                    </strong>


                    <span>

                        <?= e($entry["naziv"]) ?>

                        <br>

                        <small>

                            <?= e(
                                $entry["priimek"]
                                . " "
                                . $entry["ime"]
                            ) ?>

                            ·

                            <?= e($entry["ucilnica"]) ?>

                        </small>

                    </span>


                    <?php if ($user["vloga"] === "admin"): ?>

                        <details>

                            <summary>
                                Uredi
                            </summary>


                            <!-- UREDI -->

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="uredi"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $entry["id_urnik"] ?>"
                                >


                                <?php

                                scheduleFields(
                                    $entry,
                                    $predmeti,
                                    $ucitelji,
                                    $days
                                );

                                ?>


                                <button class="btn small">
                                    Shrani
                                </button>

                            </form>


                            <!-- IZBRIŠI -->

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="izbrisi"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $entry["id_urnik"] ?>"
                                >


                                <button
                                    class="btn red small"
                                    onclick="return confirm(
                                        'Izbrišem uro?'
                                    )"
                                >
                                    Izbriši
                                </button>

                            </form>

                        </details>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>


            <?php if (!$imaUre): ?>

                <p>
                    Ta dan ni vnešenih ur.
                </p>

            <?php endif; ?>

        </section>

    <?php endforeach; ?>

</div>


<?php pageEnd(); ?>