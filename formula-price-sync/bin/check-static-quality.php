<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = array();
foreach ( array( $root . '/formula-price-sync.php', $root . '/uninstall.php', $root . '/includes' ) as $path ) {
	if ( is_file( $path ) ) {
		$files[] = $path;
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

$errors = 0;
foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	if ( false === $source ) {
		fwrite( STDERR, "Cannot read {$file}\n" );
		++$errors;
		continue;
	}
	$tokens = token_get_all( $source );
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) && T_EVAL === $token[0] ) {
			fwrite( STDERR, "T_EVAL found in {$file} at line {$token[2]}\n" );
			++$errors;
		}
	}
}

if ( $errors > 0 ) {
	exit( 1 );
}

echo "Static policy scan: PASS (no T_EVAL tokens)\n";
