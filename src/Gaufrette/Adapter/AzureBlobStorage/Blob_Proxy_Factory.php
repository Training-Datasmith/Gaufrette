<?php

declare (strict_types=1);
namespace Gaufrette\Adapter\Azure_Blob_Storage;

use Microsoft_Azure\Storage\Blob\Blob_Rest_Proxy;
use Microsoft_Azure\Storage\Common\Services_Builder;
/**
 * Basic implementation for a Blob proxy factory.
 *
 * @author Luciano Mammino <lmammino@oryzone.com>
 */
class Blob_Proxy_Factory implements Blob_Proxy_Factory_Interface
{
    /**
     * @var string
     */
    protected $connection_string;
    /**
     * @param string $connectionString
     */
    public function __construct($connection_string)
    {
        if (!class_exists(Services_Builder::class) && !class_exists(Blob_Rest_Proxy::class)) {
            throw new \LogicException('You need to install package "microsoft/azure-storage-blob" to use this adapter');
        }
        $this->connection_string = $connection_string;
    }
    /**
     * {@inheritdoc}
     */
    public function create()
    {
        if (class_exists(Services_Builder::class)) {
            // for microsoft/azure-storage < 1.0
            return Services_Builder::get_instance()->create_blob_service($this->connection_string);
        }
        return Blob_Rest_Proxy::create_blob_service($this->connection_string);
    }
}