<?php

require __DIR__ . '/kirby/bootstrap.php';
require __DIR__ . '/site/bootstrap.php';

echo (new Kirby([
	'roots' => kneipe_roots(__DIR__),
]))->render();
