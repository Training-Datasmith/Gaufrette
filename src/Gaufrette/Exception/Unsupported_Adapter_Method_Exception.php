<?php

declare (strict_types=1);
namespace Gaufrette\Exception;

use Gaufrette\Exception;
class Unsupported_Adapter_Method_Exception extends \BadMethodCallException implements Exception
{
}