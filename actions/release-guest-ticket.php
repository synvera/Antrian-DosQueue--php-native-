<?php
// actions/release-guest-ticket.php
require_once __DIR__ . '/../includes/functions.php';

clearGuestDeviceTicket();
redirectTo('../user/index.php');