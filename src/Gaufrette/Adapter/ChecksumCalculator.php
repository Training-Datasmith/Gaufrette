<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Interface which add checksum calculation support to adapter.
 *
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
interface Checksum_Calculator
{
    /**
     * Returns the checksum of the specified key.
     *
     * @param string $key
     *
     * @return string
     */
    public function checksum($key);
}