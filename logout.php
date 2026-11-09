<?php
/**
 * DOCTOR CONNECT - Logout Controller
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

logout_user();

session_start();
set_flash('info', 'You have been safely signed out. See you soon!');
header("Location: " . BASE_URL . "/login.php");
exit;
