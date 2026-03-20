<?php

declare(strict_types=1);

namespace Gaufrette\Functional\FileStream;

use Gaufrette\Adapter\InMemory as InMemoryAdapter;
use Gaufrette\Filesystem;

class InMemoryBufferTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryAdapter([]));

        $this->registerLocalFilesystemInStream();
    }
}
