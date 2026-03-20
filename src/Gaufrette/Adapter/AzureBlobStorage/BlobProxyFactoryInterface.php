<?php

declare (strict_types=1);
namespace Gaufrette\Adapter\Azure_Blob_Storage;

/**
 * Interface to define Blob proxy factories.
 *
 * @author Luciano Mammino <lmammino@oryzone.com>
 */
interface Blob_Proxy_Factory_Interface
{
    /**
     * Creates a new instance of the Blob proxy.
     *
     * @return \MicrosoftAzure\Storage\Blob\Internal\IBlob
     */
    public function create();
}