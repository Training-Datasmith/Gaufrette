<?php

declare (strict_types=1);
namespace Gaufrette;

/**
 * Associates filesystem instances to their names.
 */
interface Filesystem_Map_Interface
{
    /**
     * Indicates whether there is a filesystem registered for the specified
     * name.
     *
     * @param string $name
     *
     * @return bool
     */
    public function has($name);
    /**
     * Returns the filesystem registered for the specified name.
     *
     * @param string $name
     *
     * @return FilesystemInterface
     *
     * @throw  \InvalidArgumentException when there is no filesystem registered
     *                                  for the specified name
     */
    public function get($name);
}