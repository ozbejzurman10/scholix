<?php

require_once "functions.php";

requireLogin();

$user = getUser();

if ($user["vloga"] === "admin") {
    redirect("admin.php");
}

if ($user["vloga"] === "ucitelj") {
    redirect("teacher.php");
}

if ($user["vloga"] === "dijak") {
    redirect("student.php");
}

?>