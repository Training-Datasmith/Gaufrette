<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Exception\Unsupported_Adapter_Method_Exception;
use League\Flysystem\Adapter_Interface;
use League\Flysystem\Util;
class Flysystem implements Adapter, List_Keys_Aware
{
    /**
     * @var AdapterInterface
     */
    private $adapter;
    /**
     * @var Config
     */
    private $config;
    /**
     * @param \League\Flysystem\Config|array|null $config
     */
    public function __construct(Adapter_Interface $adapter, $config = null)
    {
        if (!interface_exists(Adapter_Interface::class)) {
            throw new \LogicException('You need to install package "league/flysystem" to use this adapter');
        }
        $this->adapter = $adapter;
        $this->config = Util::ensure_config($config);
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        return $this->adapter->read($key)['contents'];
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        return $this->adapter->write($key, $content, $this->config);
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        return (bool) $this->adapter->has($key);
    }
    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return array_map(fn(array $content) => $content['path'], $this->adapter->list_contents());
    }
    /**
     * {@inheritdoc}
     */
    public function list_keys($prefix = ''): array
    {
        $dirs = [];
        $keys = [];
        foreach ($this->adapter->list_contents() as $content) {
            if (empty($prefix) || 0 === strpos($content['path'], $prefix)) {
                if ('dir' === $content['type']) {
                    $dirs[] = $content['path'];
                } else {
                    $keys[] = $content['path'];
                }
            }
        }
        return ['keys' => $keys, 'dirs' => $dirs];
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        return $this->adapter->get_timestamp($key);
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        return $this->adapter->delete($key);
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key)
    {
        return $this->adapter->rename($source_key, $target_key);
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key)
    {
        throw new Unsupported_Adapter_Method_Exception('isDirectory is not supported by this adapter.');
    }
}