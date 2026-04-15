<?php
session_start();
$_SESSION['user_id'] = 1; // Adjust based on biomie's actual session key; usually it's 'user_id' or 'auth_user'.
$_SERVER['REQUEST_URI'] = '/api/flight_plan_vendor_detail.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['vendor_id'] = '6A2'; // We can use one of the vendor IDs. Let's list vendors first.
// Wait, to bypass Auth cleanly, Auth::requireLogin looks at $_SESSION.
// Let's just find the exact session key used in BIomie Auth.
require 'app/Auth.php';
$_SESSION['auth_user_id'] = 1;
$_SESSION['user_id'] = 1;
require 'api/flight_plan_vendor_detail.php';
