<?php
// Optional initial-install overrides. Copy to the new data volume before installing.
return [
	'nb_parallel_refresh' => 2,
	'scheduler' => [
		'enabled' => true,
		'workers' => 2,
		'per_host' => 1,
		'worker_memory_mib' => 128,
	],
	'limits' => [
		'api_max_entries' => 1000,
		'api_max_ids' => 10000,
		'api_max_request_bytes' => 1048576,
		'api_max_batch_items' => 1000,
		'cache_duration' => 800,
		'cache_duration_min' => 60,
		'cache_duration_max' => 86400,
		'timeout' => 20,
	],
];
