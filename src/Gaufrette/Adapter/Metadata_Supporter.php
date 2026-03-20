<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Interface which add supports for metadata.
 *
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
interface Metadata_Supporter
{
    /**
     * @param string $key
     * @param array  $content
     */
    public function set_metadata($key, $content);
    /**
     * @param string $key
     *
     * @return array
     */
    public function get_metadata($key);
}