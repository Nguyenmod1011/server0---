<?php
// admin/logout.php
define('IN_ADMIN', true);
define('PUBLIC_PAGE', true);
require_once dirname(__DIR__) . '/config.php';
session_destroy();
header('Location: login.php');
exit;
