<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * interface that adds support of native listKeys to adapter.
 *
 * @author Andrew Tch <andrew.tchircoff@gmail.com>
 */
interface List_Keys_Aware
{
    /**
     * Lists keys beginning with pattern given
     * (no wildcard / regex matching).
     *
     * @param string $prefix
     *
     * @return array
     */
    public function list_keys($prefix = '');
}