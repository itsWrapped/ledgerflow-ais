
<?php

session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !in_array(
        $_SESSION["role"] ?? "",
        ["admin", "finance_officer"],
        true
    )
) {
    header("Location: ../auth/login.php");
    exit;
}
