<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: /accounting/pages/dashboard.php');
} else {
    header('Location: /accounting/pages/login.php');
}
exit;
