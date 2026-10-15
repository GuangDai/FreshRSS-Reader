<?php
declare(strict_types=1);
require dirname(__DIR__) . '/constants.php';
require LIB_PATH . '/lib_rss.php';	//Includes class autoloader

FreshRSS_Context::initSystem();
Minz_Request::forward(['c' => 'index', 'a' => 'index',
	'params' => array_filter($_GET, 'is_string', ARRAY_FILTER_USE_KEY)], true);
