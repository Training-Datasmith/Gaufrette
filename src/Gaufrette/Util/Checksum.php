<?php

declare(strict_types=1);

namespace Gaufrette\Util;

/**
 * Checksum utils.
 *
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class Checksum
{
    /**
     * Returns the checksum of the given content.
     *
     * @param string $content
     */
    public static function fromContent($content): string
    {
        return md5($content);
    }

    /**
     * Returns the checksum of the specified file.
     *
     * @param string $filename
     *
     * @return string
     */
    public static function fromFile($filename)
    {
        return md5_file($filename);
    }
}
