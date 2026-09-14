<?php
/**
 * Build the distributable plugin ZIP.
 *
 * Until now this was re-derived by hand at every release — `.distignore`
 * existed but nothing consumed it, and each session wrote its own throwaway
 * walk (see the 2026-09-08 release-packaging entry in STATE.md, which asks for
 * exactly this script once packaging recurred). Two properties are easy to get
 * wrong by hand and both have bitten this plugin before:
 *
 * 1. **Every entry must sit under a `simple-performance-for-wordpress/` root
 *    directory.** Without it WordPress treats an upload as a NEW plugin rather
 *    than an overwrite of the installed one, and the admin never gets the
 *    "replace current with uploaded" prompt. That was the 1.11.1 fix.
 * 2. **`build/` is gitignored but MUST ship.** The admin screen is a compiled
 *    React app; a ZIP without `build/` installs and then renders nothing.
 *
 * Usage:
 *   npm run build && php tools/make-zip.php [--out=DIR]
 *
 * Exits non-zero with an explanation rather than shipping something broken.
 *
 * @package Simple_Performance_For_WordPress
 */

// phpcs:ignoreFile -- build tooling, not shipped code (excluded via .distignore).

declare( strict_types = 1 );

const SLUG = 'simple-performance-for-wordpress';

$root = dirname( __DIR__ );

$out_dir = $root;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--out=' ) ) {
		$out_dir = rtrim( substr( $arg, 6 ), '/' );
	}
}

/**
 * Read .distignore into two buckets.
 *
 * The file mixes two kinds of pattern and they are not interchangeable:
 * bare top-level names (`src`, `tests`, `STATE.md`) exclude that path relative
 * to the plugin root only, while globs (`*.log`, `.DS_Store`) match a basename
 * at any depth. Treating the first kind as a basename match would drop, say, a
 * nested `tests` directory that belongs in the release; treating the second as
 * top-level-only would ship `.DS_Store` files from subdirectories.
 */
function read_distignore( string $path ): array {
	$top   = array();
	$globs = array();

	foreach ( file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}

		if ( str_contains( $line, '*' ) || str_contains( $line, '?' ) ) {
			$globs[] = $line;
			continue;
		}

		$top[] = rtrim( $line, '/' );
	}

	return array( $top, $globs );
}

function is_excluded( string $rel, array $top, array $globs ): bool {
	foreach ( $top as $name ) {
		if ( $rel === $name || str_starts_with( $rel, $name . '/' ) ) {
			return true;
		}
	}

	foreach ( $globs as $glob ) {
		if ( fnmatch( $glob, basename( $rel ) ) ) {
			return true;
		}
	}

	return false;
}

$distignore = $root . '/.distignore';

if ( ! is_readable( $distignore ) ) {
	fwrite( STDERR, "error: .distignore not found at {$distignore}\n" );
	exit( 1 );
}

// The version the ZIP is named for comes from the plugin header, so the
// filename can never disagree with what WordPress will report once installed.
$bootstrap = file_get_contents( $root . '/' . SLUG . '.php' );

if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $bootstrap, $m ) ) {
	fwrite( STDERR, "error: could not read Version from the plugin header\n" );
	exit( 1 );
}

$version = trim( $m[1] );

// A ZIP without the compiled admin app installs cleanly and then shows a blank
// settings screen — fail loudly instead.
if ( ! is_file( $root . '/build/index.js' ) || ! is_file( $root . '/build/index.asset.php' ) ) {
	fwrite( STDERR, "error: build/ is missing or incomplete — run `npm run build` first.\n" );
	exit( 1 );
}

list( $top, $globs ) = read_distignore( $distignore );

$zip_path = $out_dir . '/' . SLUG . '-' . $version . '.zip';

if ( file_exists( $zip_path ) && ! unlink( $zip_path ) ) {
	fwrite( STDERR, "error: could not replace {$zip_path}\n" );
	exit( 1 );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "error: could not create {$zip_path}\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		function ( $file ) use ( $root, $top, $globs ) {
			$rel = substr( $file->getPathname(), strlen( $root ) + 1 );

			// Pruning at the directory level keeps the walk from descending
			// into node_modules and vendor, which is the difference between a
			// fast build and one that stats a hundred thousand files.
			return ! is_excluded( str_replace( '\\', '/', $rel ), $top, $globs );
		}
	),
	RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;

foreach ( $iterator as $file ) {
	if ( $file->isDir() ) {
		continue;
	}

	$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

	// The root wrapper: this is what makes WordPress offer to overwrite the
	// installed plugin rather than install a second copy alongside it.
	$zip->addFile( $file->getPathname(), SLUG . '/' . $rel );
	++$count;
}

$zip->close();

printf( "%s\n%d files, %d KB\n", $zip_path, $count, (int) round( filesize( $zip_path ) / 1024 ) );
