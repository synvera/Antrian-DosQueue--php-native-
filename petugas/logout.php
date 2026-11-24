<?php
// petugas/logout.php
require_once __DIR__ . '/../includes/auth.php';

logoutUser();
redirectTo('login.php');