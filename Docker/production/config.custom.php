<?php
// Initial settings for a new data volume. Installed configurations remain authoritative.
return [
	'nb_parallel_refresh' => 2,
	'scheduler' => [
		'enabled' => true,
		'workers' => 2,
		'per_host' => 1,
		'worker_memory_mib' => 128,
	],
];
