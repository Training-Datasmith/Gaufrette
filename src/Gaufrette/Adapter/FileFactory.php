<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\File;
use Gaufrette\Filesystem;
/**
 * Interface for the file creation class.
 *
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
interface File_Factory
{
    /**
     * Creates a new File instance and returns it.
     *
     * @param string     $key
     *
     * @return File
     */
    public function create_file($key, Filesystem $filesystem);
}