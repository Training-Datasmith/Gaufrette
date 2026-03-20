<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

/**
 * Safe local adapter that encodes key to avoid the use of the directories
 * structure.
 *
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class Safe_Local extends Local
{
    /**
     * {@inheritdoc}
     */
    public function compute_key($path): string
    {
        return base64_decode(parent::compute_key($path));
    }
    /**
     * {@inheritdoc}
     */
    protected function compute_path($key)
    {
        return parent::compute_path(base64_encode($key));
    }
}