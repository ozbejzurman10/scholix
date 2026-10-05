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
        && $file["size"] <= 10 * 1024 * 1024;
}

function ensureUploadDirectory($directory)
{
    $path = __DIR__ . "/" . $directory;
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
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

        <link rel="stylesheet" href="style.css">

    </head>

    <body>

    <header class="navbar">

        <div class="nav-container">

            <a href="dashboard.php" class="logo">
                Scholix
            </a>

            <nav>

                <a href="dashboard.php">
                    Nadzorna plošča
                </a>


                <?php if ($user && $user["vloga"] === "admin"): ?>

                    <a href="admin.php">
                        Administrator
                    </a>

                    <a href="urnik.php">Urnik</a>
                    <a href="ocene.php">Ocene</a>
                    <a href="izostanki.php">Izostanki</a>

                <?php endif; ?>


                <?php if ($user && $user["vloga"] === "ucitelj"): ?>

                    <a href="teacher.php">
                        Učitelj
                    </a>

                    <a href="urnik.php">Urnik</a>
                    <a href="ocene.php">Ocene</a>
                    <a href="izostanki.php">Izostanki</a>

                <?php endif; ?>


                <?php if ($user && $user["vloga"] === "dijak"): ?>

                    <a href="student.php">
                        Dijak
                    </a>

                    <a href="urnik.php">Urnik</a>
                    <a href="ocene.php">Ocene</a>
                    <a href="izostanki.php">Izostanki</a>

                    <a href="profile.php">
                        Profil
                    </a>

                <?php endif; ?>


                <?php if ($user): ?>

                    <span class="user-name">

                        <?= e($user["ime"]) ?>
                        <?= e($user["priimek"]) ?>

                    </span>

                    <a href="logout.php">
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
