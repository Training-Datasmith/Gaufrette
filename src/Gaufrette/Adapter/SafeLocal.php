<?php

declare(strict_types=1);

namespace Gaufrette\Adapter;

/**
 * Safe local adapter that encodes key to avoid the use of the directories
 * structure.
 *
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class SafeLocal extends Local
{
    /**
     * {@inheritdoc}
     */
    public function computeKey($path): string
    {
        return base64_decode(parent::computeKey($path));
    }

    /**
     * {@inheritdoc}
     */
    protected function computePath($key)
    {
        return parent::computePath(base64_encode($key));
    }
}
