<?php
declare(strict_types=1);

/**
 * MINZ - Copyright 2011 Marien Fressinaud
 * Sous licence AGPL3 <https://www.gnu.org/licenses/>
*/

/**
 * The Minz_Log class is used to log errors and warnings
 */
class Minz_Log {
	/**
	 * Syslog priority corresponding to each value accepted by the `log_level` system setting,
	 * from the most to the least severe.
	 * @var array<string,int>
	 */
	private const LOG_LEVELS = [
		'error' => LOG_ERR,
		'warning' => LOG_WARNING,
		'notice' => LOG_NOTICE,
		'info' => LOG_INFO,
		'debug' => LOG_DEBUG,
	];

	/**
	 * Records a message in a specific log file
	 * The message is not logged if
	 * 	- environment = SILENT
	 * 	- level is less severe than the threshold determined by `log_level`,
	 * 	  or, by default, by `environment` (PRODUCTION keeps only warnings and errors)
	 * @param string $information error message / information to record
	 * @param int $level error level https://www.php.net/function.syslog
	 * @param string $file_name log file
	 * @throws Minz_PermissionDeniedException
	 */
	public static function record(string $information, int $level, ?string $file_name = null): void {
		$env = getenv('FRESHRSS_ENV');
		$log_level = '';
		try {
			$conf = Minz_Configuration::get('system');
			$log_level = $conf->log_level;
			if ($env == '') {
				$env = $conf->environment;
			}
		} catch (Minz_ConfigurationException $e) {
			if ($env == '') {
				$env = 'production';
			}
		}
		if ($log_level === '' || !isset(self::LOG_LEVELS[$log_level])) {
			$log_level = match ($env) {
				'silent' => 'error',
				'production' => 'warning',
				default => 'debug',
			};
		}

		if (! ($env === 'silent' || $level > self::LOG_LEVELS[$log_level])) {
			$username = Minz_User::name() ?? Minz_User::INTERNAL_USER;
			if ($file_name == null) {
				$file_name = join_path(USERS_PATH, $username, LOG_FILENAME);
			}

			$level_labels = array_flip(self::LOG_LEVELS);
			if (!isset($level_labels[$level])) {
				$level = LOG_INFO;
			}
			$level_label = $level_labels[$level];

			$log = '[' . date('r') . '] [' . $level_label . '] --- ' . str_replace(["\r", "\n"], ' ', $information) . "\n";

			if (defined('COPY_LOG_TO_SYSLOG') && COPY_LOG_TO_SYSLOG) {
				syslog($level, '[' . $username . '] ' . trim($log));
			}

			self::appendLog($file_name, $log);
		}
	}

	/**
	 * Inspect, rotate, and append under the same lock. fstat() avoids PHP's pathname stat cache.
	 * @throws Minz_PermissionDeniedException
	 */
	private static function appendLog(string $file_name, string $log): void {
		$fp = fopen($file_name, 'c+b');
		if (!is_resource($fp)) {
			throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
		}
		try {
			if (!flock($fp, LOCK_EX)) {
				throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
			}
			$stat = fstat($fp);
			$maxSize = defined('MAX_LOG_SIZE') ? MAX_LOG_SIZE : 1048576;
			if ($maxSize > 0 && is_array($stat) && $stat['size'] > $maxSize) {
				$retainedBytes = (int)($maxSize / 2);
				$content = '';
				if ($retainedBytes > 0) {
					if (fseek($fp, -$retainedBytes, SEEK_END) !== 0) {
						throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
					}
					$content = fread($fp, $retainedBytes);
					if ($content === false) {
						throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
					}
					// Discard the first partial record rather than leaving an invalid log line.
					$newline = strpos($content, "\n");
					$content = $newline === false ? '' : substr($content, $newline + 1);
				}
				if (!rewind($fp) || !ftruncate($fp, 0)) {
					throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
				}
				self::writeLog($fp, $content . sprintf("[%s] [notice] --- Log rotate.\n", date('r')), $file_name);
			}
			if (fseek($fp, 0, SEEK_END) !== 0) {
				throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
			}
			self::writeLog($fp, $log, $file_name);
			if (!fflush($fp)) {
				throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
			}
		} finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}

	/** @param resource $fp */
	private static function writeLog($fp, string $content, string $file_name): void {
		for ($offset = 0, $length = strlen($content); $offset < $length; $offset += $written) {
			$written = fwrite($fp, substr($content, $offset));
			if ($written === false || $written === 0) {
				throw new Minz_PermissionDeniedException($file_name, Minz_Exception::ERROR);
			}
		}
	}

	/**
	 * Some helpers to Minz_Log::record() method
	 * Parameters are the same of those of the record() method.
	 * @throws Minz_PermissionDeniedException
	 */
	public static function debug(string $msg, ?string $file_name = null): void {
		self::record($msg, LOG_DEBUG, $file_name);
	}
	/** @throws Minz_PermissionDeniedException */
	public static function notice(string $msg, ?string $file_name = null): void {
		self::record($msg, LOG_NOTICE, $file_name);
	}
	/** @throws Minz_PermissionDeniedException */
	public static function warning(string $msg, ?string $file_name = null): void {
		self::record($msg, LOG_WARNING, $file_name);
	}
	/** @throws Minz_PermissionDeniedException */
	public static function error(string $msg, ?string $file_name = null): void {
		self::record($msg, LOG_ERR, $file_name);
	}
}
