<?php

require_once __DIR__ . '/../app/auth.php';

require_auth();
logout();
header('Location: login.php');
exit;
