<?php
/**
 * WP-CLI command that restores a site's missing media files from a zip.
 *
 * For a database moved to another host without its uploads: each attachment whose file is missing gets it from the
 * zip, at the path the attachment already names. It never creates, changes or deletes an attachment.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\CLI;

use Generator;
use WP_CLI;
use WP_Query;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

const COMMAND = 'uat-checklist import-media';

/**
 * Attachments read per query.
 */
const BATCH = 100;

/**
 * Seconds allowed for the zip to download.
 */
const DOWNLOAD_TIMEOUT = 300;

/**
 * Registers the command when WP-CLI is running.
 *
 * @return void
 */
function bootstrap(): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( COMMAND, __NAMESPACE__ . '\\import_media' );
	}
}

/**
 * Writes this site's missing media files from a zip, at the paths their attachments already name.
 *
 * The zip holds files as they sit under wp-content/uploads: sites/<site ID>/<year>/<month>/<file> for a network
 * site. Run it once per site with --url. A file already present is left alone, so running it twice is harmless.
 *
 * ## OPTIONS
 *
 * --zip=<url>
 * : HTTPS address of the zip, given when the command runs. A Google Drive file shared with anyone who has the
 * link works as https://drive.usercontent.google.com/download?id=<file ID>&export=download&confirm=t
 *
 * [--dry-run]
 * : Report what would be written, and write nothing.
 *
 * ## EXAMPLES
 *
 *     wp --url=example.com uat-checklist import-media --zip=https://example.com/uat-media.zip --dry-run
 *
 * @param string[]             $args       Positional arguments, none.
 * @param array<string, mixed> $assoc_args Named arguments: zip, dry-run.
 * @return void
 */
function import_media( array $args, array $assoc_args ): void {
	$url     = (string) $assoc_args['zip'];
	$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

	if ( wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
		WP_CLI::error( '--zip takes an https:// address.' );
	}

	require_once ABSPATH . 'wp-admin/inc/file.php';

	WP_CLI::log( 'Downloading the zip…' );
	$archive = download_url( $url, DOWNLOAD_TIMEOUT );
	if ( is_wp_error( $archive ) ) {
		WP_CLI::error( 'The zip did not download: ' . $archive->get_error_message() );
	}

	$zip = new ZipArchive();
	if ( $zip->open( $archive ) !== true ) {
		wp_delete_file( $archive );
		WP_CLI::error(
			'The download is not a zip. '
			. 'A Google Drive file must be shared with anyone who has the link, '
			. 'and the address must be its direct download link.'
		);
	}

	$counts = restore_files( $zip, $dry_run );

	$zip->close();
	wp_delete_file( $archive );

	WP_CLI::success(
		sprintf(
			'%1$d %2$s, %3$d already present, %4$d not in the zip.',
			$counts['written'],
			$dry_run ? 'would be written' : 'written',
			$counts['present'],
			$counts['absent']
		)
	);
}

/**
 * Writes the zip's copy of every attachment file this site is missing.
 *
 * @param ZipArchive $zip     Open zip.
 * @param bool       $dry_run Whether to report instead of writing.
 * @return array<string, int> Files written, already present, and missing from the zip.
 */
function restore_files( ZipArchive $zip, bool $dry_run ): array {
	$counts  = [
		'written' => 0,
		'present' => 0,
		'absent'  => 0,
	];
	$basedir = wp_get_upload_dir()['basedir'];
	// The main site's uploads sit at the top of uploads/, every other site's under sites/<ID>/.
	$prefix = is_main_site() ? '' : 'sites/' . get_current_blog_id() . '/';

	foreach ( attached_files() as $file ) {
		if ( file_exists( $basedir . '/' . $file ) ) {
			++$counts['present'];
			continue;
		}

		$bits = $zip->getFromName( $prefix . $file );
		if ( $bits === false ) {
			++$counts['absent'];
			WP_CLI::warning( 'Not in the zip: ' . $prefix . $file );
			continue;
		}

		if ( ! $dry_run ) {
			write_file( $file, $bits );
		}
		++$counts['written'];
		WP_CLI::log( ( $dry_run ? 'Would write ' : 'Wrote ' ) . $file );
	}

	return $counts;
}

/**
 * Yields every attachment's stored file path, relative to the uploads directory.
 *
 * @return Generator<int, string> Paths, keyed by attachment ID.
 */
function attached_files(): Generator {
	$page = 1;

	do {
		$ids = ( new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => BATCH,
				'paged'          => $page++,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		) )->posts;

		foreach ( $ids as $id ) {
			$file = get_post_meta( $id, '_wp_attached_file', true );
			if ( is_string( $file ) && $file !== '' ) {
				yield $id => $file;
			}
		}
	} while ( count( $ids ) === BATCH );
}

/**
 * Writes one file at its attachment's path through WordPress, so it lands wherever the host stores uploads.
 *
 * @param string $file  Path relative to the uploads directory, such as 2026/10/uat-hero-editor.png.
 * @param string $bits  File contents.
 * @return void
 */
function write_file( string $file, string $bits ): void {
	// wp_upload_bits() writes into a year/month folder, or the top of uploads/ on a site that does not use them.
	if ( preg_match( '#^(?:(\d{4}/\d{2})/)?([^/]+)$#', $file, $parts ) !== 1 ) {
		WP_CLI::error( 'Not in a year/month folder or at the top of uploads, so it cannot be written: ' . $file );
	}

	$written = wp_upload_bits( $parts[2], null, $bits, $parts[1] !== '' ? $parts[1] : null );
	if ( $written['error'] !== false ) {
		WP_CLI::error( $file . ': ' . $written['error'] );
	}

	// WordPress renames a file whose name is taken. The path was checked as free, so a rename means it was not.
	if ( $written['file'] !== wp_get_upload_dir()['basedir'] . '/' . $file ) {
		wp_delete_file( $written['file'] );
		WP_CLI::error( 'Written under another name, and removed: ' . $written['file'] );
	}
}
