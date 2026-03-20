<?php

declare(strict_types=1);

/**
 * Example: Read and write files using the local filesystem adapter.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Gaufrette\Adapter\Local;
use Gaufrette\Filesystem;

$adapter    = new Local(sys_get_temp_dir() . '/gaufrette_demo', true);
$filesystem = new Filesystem($adapter);

// Write a file.
$filesystem->write('hello.txt', 'Hello, Gaufrette!');
echo "Written: hello.txt" . PHP_EOL;

// Check existence.
echo "Exists: " . ($filesystem->has('hello.txt') ? 'yes' : 'no') . PHP_EOL;

// Read it back.
echo "Content: " . $filesystem->read('hello.txt') . PHP_EOL;

// List all keys.
echo "Keys: " . implode(', ', $filesystem->keys()) . PHP_EOL;

// Delete.
$filesystem->delete('hello.txt');
echo "Deleted. Exists: " . ($filesystem->has('hello.txt') ? 'yes' : 'no') . PHP_EOL;
