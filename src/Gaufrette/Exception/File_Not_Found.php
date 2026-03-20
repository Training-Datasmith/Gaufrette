<?php

declare (strict_types=1);
namespace Gaufrette\Exception;

use Gaufrette\Exception;
/**
 * Exception to be thrown when a file was not found.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class File_Not_Found extends \RuntimeException implements Exception
{
    private $key;
    public function __construct($key, $code = 0, \Exception $previous = null)
    {
        $this->key = $key;
        parent::__construct(sprintf('The file "%s" was not found.', $key), $code, $previous);
    }
    public function get_key()
    {
        return $this->key;
    }
}