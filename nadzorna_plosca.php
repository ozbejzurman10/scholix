<?php

require_once "funkcije.php";

requireLogin();

$user = getUser();

if ($user["vloga"] === "admin") {
    redirect("administrator.php");
}

if ($user["vloga"] === "ucitelj") {
    redirect("ucitelj.php");
}

if ($user["vloga"] === "dijak") {
    redirect("dijak.php");
}

?>