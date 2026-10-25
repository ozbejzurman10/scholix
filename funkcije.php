<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "baza.php";


function e($text)
{
    return htmlspecialchars($text ?? "", ENT_QUOTES, "UTF-8");
}


function redirect($page)
{
    header("Location: " . $page);
    exit;
}


function requireLogin()
{
    if (!isset($_SESSION["user_id"])) {
        redirect("prijava.php");
    }
}


function requireRole($role)
{
    requireLogin();

    if (($_SESSION["role"] ?? "") !== $role) {
        redirect("nadzorna_plosca.php");
    }
}


function getUser()
{
    global $conn;

    if (!isset($_SESSION["user_id"])) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT * FROM Uporabnik WHERE id_uporabnik = ?"
    );

    $stmt->execute([$_SESSION["user_id"]]);

    return $stmt->fetch();
}


function safeFileName($name)
{
    $name = preg_replace('/[\/\\\\:*?"<>|]+/u', "-", $name);
    $name = preg_replace('/\s+/', " ", $name);

    $name = trim($name, ". ");

    return $name !== "" ? $name : "datoteka";
}

function uploadIsValid($file)
{
    return isset($file["error"], $file["tmp_name"], $file["name"])
        && $file["error"] === UPLOAD_ERR_OK
        && is_uploaded_file($file["tmp_name"])
        && $file["size"] > 0
        && $file["size"] <= 40 * 1024 * 1024;
}

function ensureUploadDirectory($directory)
{
    $path = __DIR__ . "/" . $directory;
    if (file_exists($path) && !is_dir($path)) {
        @unlink($path);
    }
    if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Mape za nalaganje ni mogoče ustvariti.");
    }

    return $path;
}


function flash($message)
{
    $_SESSION["message"] = $message;
}


function showFlash()
{
    if (isset($_SESSION["message"])) {

        $message = $_SESSION["message"];

        unset($_SESSION["message"]);

        return $message;
    }

    return "";
}


function isTeacherRazrednik($userId)
{
    global $conn;
    if (!$userId) {
        return false;
    }
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        $st = $conn->prepare("SELECT 1 FROM Oddelek WHERE razrednik_id = ? LIMIT 1");
        $st->execute([$userId]);
        $cache[$userId] = (bool) $st->fetchColumn();
    }
    return $cache[$userId];
}


function pageStart($title)
{
    $user = getUser();
    ?>

    <!DOCTYPE html>
    <html lang="sl">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">

        <title><?= e($title) ?> - Scholix</title>

        <link rel="stylesheet" href="stil.css?v=<?= filemtime(__DIR__ . "/stil.css") ?>">

    </head>

    <body>

    <header class="navbar">

        <div class="nav-container">

            <span class="logo">
                Scholix
            </span>

            <nav>

                <?php
                $domovLabel = "Nadzorna plošča";
                $currentScript = basename($_SERVER["SCRIPT_NAME"] ?? "");
                $isHomeActive = in_array($currentScript, ["nadzorna_plosca.php", "dijak.php", "ucitelj.php", "administrator.php"], true);
                if ($user && $user["vloga"] === "ucitelj") {
                    $domovLabel = "Učiteljski modul";
                } elseif ($user && $user["vloga"] === "dijak") {
                    $domovLabel = "Moja učilnica";
                }
                ?>

                <a href="nadzorna_plosca.php" class="nav-link <?= $isHomeActive ? "active" : "" ?>">
                    <?= e($domovLabel) ?>
                </a>

                <?php if ($user && $user["vloga"] === "dijak"): ?>
                    <a href="naloge.php" class="nav-link <?= $currentScript === "naloge.php" ? "active" : "" ?>">
                        Naloge
                    </a>
                <?php endif; ?>

                <a href="urnik.php" class="nav-link <?= $currentScript === "urnik.php" ? "active" : "" ?>">Urnik</a>
                <a href="govorilne.php" class="nav-link <?= $currentScript === "govorilne.php" ? "active" : "" ?>">Govorilne ure</a>
                <a href="ocene.php" class="nav-link <?= $currentScript === "ocene.php" ? "active" : "" ?>">Ocene</a>

                <?php
                $lahkoVidiIzostanke = true;
                if ($user && $user["vloga"] === "ucitelj") {
                    $lahkoVidiIzostanke = isTeacherRazrednik($user["id_uporabnik"]);
                }
                ?>
                <?php if ($lahkoVidiIzostanke): ?>
                    <a href="izostanki.php" class="nav-link <?= $currentScript === "izostanki.php" ? "active" : "" ?>">Izostanki</a>
                <?php endif; ?>

                <?php if ($user): ?>

                    <a href="profil.php" class="user-name" title="Nastavitve profila">
                        <?= e($user["ime"]) ?> <?= e($user["priimek"]) ?>
                    </a>

                    <a href="odjava.php" class="btn-logout">
                        Odjava
                    </a>

                <?php endif; ?>

            </nav>

        </div>

    </header>


    <main class="container">

    <?php

    $message = showFlash();

    if ($message !== ""):

    ?>

        <div class="alert">
            <?= e($message) ?>
        </div>

    <?php

    endif;
}


function pageEnd()
{
    ?>

    </main>

    <script src="skripta.js"></script>

    </body>

    </html>

    <?php
}

?>
