<?php
// Legacy form path retains POST and CSRF requirements.
$_POST['action'] = 'save_reading';
require __DIR__ . '/save.php';
