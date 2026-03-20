<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Interface which add mime type provider support to adapter.
 *
 * @author Gildas Quemener <gildas.quemener@gmail.com>
 */
interface Mime_Type_Provider
{
    /**
     * Returns the mime type of the specified key.
     *
     * @param string $key
     *
     * @return string
     */
    public function mime_type($key);
}