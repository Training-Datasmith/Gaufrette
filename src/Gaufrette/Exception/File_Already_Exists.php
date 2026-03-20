<?php

declare (strict_types=1);
namespace Gaufrette\Exception;

use Gaufrette\Exception;
/**
 * Exception to be thrown when a file already exists.
 *
 * @author Benjamin Dulau <benjamin.dulau@gmail.com>
 */
class File_Already_Exists extends \RuntimeException implements Exception
{
    private $key;
    public function __construct($key, $code = 0, \Exception $previous = null)
    {
        $this->key = $key;
        parent::__construct(sprintf('The file %s already exists and can not be overwritten.', $key), $code, $previous);
    }
    public function get_key()
    {
        return $this->key;
    }
}