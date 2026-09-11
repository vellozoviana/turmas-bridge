<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$failures = array();

foreach ($iterator as $file) {
	if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
		continue;
	}
	$path = $file->getPathname();
	if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
		continue;
	}
	passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $status);
	if ($status !== 0) {
		$failures[] = $path;
	}
}

if ($failures !== array()) {
	fwrite(STDERR, "PHP lint failed:\n" . implode("\n", $failures) . "\n");
	exit(1);
}
