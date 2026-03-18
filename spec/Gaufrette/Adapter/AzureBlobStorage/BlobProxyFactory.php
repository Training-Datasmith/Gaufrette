<?php

declare(strict_types=1);

namespace spec\Gaufrette\Adapter\AzureBlobStorage;

use PhpSpec\ObjectBehavior;

class BlobProxyFactory extends ObjectBehavior
{
    /**
     * @param string $connectionString
     */
    public function let($connectionString)
    {
        $this->beConstructedWith($connectionString);
    }

    public function it_should_be_initializable()
    {
        $this->shouldHaveType('Gaufrette\Adapter\AzureBlobStorage\BlobProxyFactory');
        $this->shouldHaveType('Gaufrette\Adapter\AzureBlobStorage\BlobProxyFactoryInterface');
    }
}
