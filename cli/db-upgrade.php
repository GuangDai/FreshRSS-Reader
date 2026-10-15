#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/_cli.php';

$cliOptions = new class extends CliOptionsParser {
	public string $user;

	public function __construct() {
		$this->addRequiredOption('user', new CliOption('user'));
		parent::__construct();
	}
};

if (!empty($cliOptions->errors)) {
	fail('FreshRSS error: ' . array_shift($cliOptions->errors) . "\n" . $cliOptions->usage);
}

$username = cliInitUser($cliOptions->user);
$databaseDAO = FreshRSS_Factory::createDatabaseDAO($username);
echo 'FreshRSS upgrading reader indexes for user “', $username, "”…\n";
done($databaseDAO->upgradeReaderIndexes());
