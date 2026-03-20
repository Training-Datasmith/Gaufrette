<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Interface for the stream creation class.
 *
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
interface Stream_Factory
{
    /**
     * Creates a new stream instance of the specified file.
     *
     * @param string $key
     *
     * @return \Gaufrette\Stream
     */
    public function create_stream($key);
}