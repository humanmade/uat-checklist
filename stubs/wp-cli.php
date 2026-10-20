<?php
/**
 * PHPStan stub for the WP-CLI calls made by first-party commands.
 *
 * WP-CLI is supplied by the platform at runtime and is not in this repository.
 * This file is never loaded by WordPress; PHPStan reads it through `scanFiles`
 * in `phpstan.neon.dist`, so `inc/cli.php`
 * resolves instead of reporting `class.notFound`.
 *
 * Signatures taken from wp-cli/wp-cli's `php/class-wp-cli.php` and
 * `php/utils.php`. Only the methods first-party code calls are stubbed.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace {

	defined( 'ABSPATH' ) || exit;

	/**
	 * WP-CLI's static API.
	 */
	class WP_CLI {

		/**
		 * Registers a command.
		 *
		 * @param string                          $name     Name for the command (e.g. "post list" or "site empty").
		 * @param callable|object|string|string[] $callable Command implementation as a class, function or closure.
		 * @param array<string, mixed>            $args     Optional registration parameters.
		 * @return bool True on success, false if deferred.
		 */
		public static function add_command( $name, $callable, $args = [] ) {
			return true;
		}

		/**
		 * Writes a message to STDOUT.
		 *
		 * @param string $message Message to write to STDOUT.
		 * @param bool   $newline Whether to append a newline. Default true.
		 * @return void
		 */
		public static function log( $message, $newline = true ) {
		}

		/**
		 * Writes a success message to STDOUT.
		 *
		 * @param string $message Message to write to STDOUT.
		 * @return void
		 */
		public static function success( $message ) {
		}

		/**
		 * Writes a warning to STDERR.
		 *
		 * @param string|WP_Error|Exception|Throwable $message Message to write to STDERR.
		 * @return void
		 */
		public static function warning( $message ) {
		}

		/**
		 * Writes an error to STDERR and, by default, exits.
		 *
		 * @param string|WP_Error|Exception|Throwable $message Message to write to STDERR.
		 * @param boolean|int                         $exit    True defaults to exit(1).
		 * @return null
		 * @phpstan-return ($exit is true|positive-int ? never : void)
		 */
		public static function error( $message, $exit = true ) {
			return null;
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * Returns the flag value or, if it's not set, the default.
	 *
	 * @param array<string|int, mixed> $assoc_args Arguments array.
	 * @param string|int               $flag       Flag to get the value.
	 * @param mixed                    $default    Default value for the flag.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
}
