<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Interface which add size calculation support to adapter.
 *
 * @author Markus Poerschke <markus@eluceo.de>
 */
interface Size_Calculator
{
    /**
     * Returns the size of the specified key.
     *
     * @param string $key
     *
     * @return int
     */
    public function size($key);
}