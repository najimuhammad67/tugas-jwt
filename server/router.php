<?php
// router.php -- HANYA jika memakai "php -S" (tanpa Apache), karena php -S tidak membaca .htaccess.
// Jalankan dari folder server:   php -S localhost:8000 router.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
