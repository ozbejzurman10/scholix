<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";


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
        redirect("login.php");
    }
}


function requireRole($role)
{
    requireLogin();

    if (($_SESSION["role"] ?? "") !== $role) {
        redirect("dashboard.php");
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

        <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">

    </head>

    <body>

    <header class="navbar">

        <div class="nav-container">

            <a href="dashboard.php" class="logo">
                Scholix
            </a>

            <nav>

                <?php
                $domovLabel = "Nadzorna plošča";
                if ($user && $user["vloga"] === "ucitelj") {
                    $domovLabel = "Učiteljski modul";
                } elseif ($user && $user["vloga"] === "dijak") {
                    $domovLabel = "Moja učilnica";
                }
                ?>

                <a href="dashboard.php" class="nav-link">
                    <?= e($domovLabel) ?>
                </a>

                <a href="urnik.php" class="nav-link">Urnik</a>
                <a href="ocene.php" class="nav-link">Ocene</a>
                <a href="izostanki.php" class="nav-link">Izostanki</a>

                <?php if ($user): ?>

                    <a href="profile.php" class="user-name" title="Nastavitve profila">
                        <?= e($user["ime"]) ?> <?= e($user["priimek"]) ?>
                    </a>

                    <a href="logout.php" class="btn-logout">
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

    <script src="script.js"></script>

    </body>

    </html>

    <?php
}

?>
