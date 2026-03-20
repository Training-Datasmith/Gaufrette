<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Adapter\Azure_Blob_Storage\Blob_Proxy_Factory_Interface;
use Gaufrette\Util;
use Microsoft_Azure\Storage\Blob\Models\Blob;
use Microsoft_Azure\Storage\Blob\Models\Blob_Service_Options;
use Microsoft_Azure\Storage\Blob\Models\Container;
use Microsoft_Azure\Storage\Blob\Models\Create_Blob_Options;
use Microsoft_Azure\Storage\Blob\Models\Create_Block_Blob_Options;
use Microsoft_Azure\Storage\Blob\Models\Create_Container_Options;
use Microsoft_Azure\Storage\Blob\Models\List_Blobs_Options;
use Microsoft_Azure\Storage\Common\Exceptions\Service_Exception;
/**
 * Microsoft Azure Blob Storage adapter.
 *
 * @author Luciano Mammino <lmammino@oryzone.com>
 * @author Paweł Czyżewski <pawel.czyzewski@enginewerk.com>
 */
class Azure_Blob_Storage implements Adapter, Metadata_Supporter, Size_Calculator, Checksum_Calculator, Mime_Type_Provider
{
    /**
     * Error constants.
     */
    public const ERROR_CONTAINER_ALREADY_EXISTS = 'ContainerAlreadyExists';
    public const ERROR_CONTAINER_NOT_FOUND = 'ContainerNotFound';
    protected \Gaufrette\Adapter\Azure_Blob_Storage\Blob_Proxy_Factory_Interface $blob_proxy_factory;
    /**
     * @var string
     */
    protected $container_name;
    /**
     * @var bool
     */
    protected $detect_content_type;
    /**
     * @var \MicrosoftAzure\Storage\Blob\Internal\IBlob
     */
    protected $blob_proxy;
    /**
     * @var bool
     */
    protected $multi_container_mode = false;
    /**
     * @var CreateContainerOptions
     */
    protected $create_container_options;
    /**
     * @param string|null                                $containerName
     * @param bool                                       $create
     * @param bool                                       $detectContentType
     * @throws \RuntimeException
     */
    public function __construct(Blob_Proxy_Factory_Interface $blob_proxy_factory, $container_name = null, $create = false, $detect_content_type = true)
    {
        $this->blob_proxy_factory = $blob_proxy_factory;
        $this->container_name = $container_name;
        $this->detect_content_type = $detect_content_type;
        if (null === $container_name) {
            $this->multi_container_mode = true;
        } elseif ($create) {
            $this->create_container($container_name);
        }
    }
    /**
     * @return CreateContainerOptions
     */
    public function get_create_container_options()
    {
        return $this->create_container_options;
    }
    public function set_create_container_options(Create_Container_Options $options): void
    {
        $this->create_container_options = $options;
    }
    /**
     * Creates a new container.
     *
     *
     * @throws \RuntimeException if cannot create the container
     */
    public function create_container(string $container_name, Create_Container_Options $options = null): void
    {
        $this->init();
        if (null === $options) {
            $options = $this->get_create_container_options();
        }
        try {
            $this->blob_proxy->create_container($container_name, $options);
        } catch (Service_Exception $e) {
            $error_code = $this->get_error_code_from_service_exception($e);
            if ($error_code !== self::ERROR_CONTAINER_ALREADY_EXISTS) {
                throw new \RuntimeException(sprintf('Failed to create the configured container "%s": %s (%s).', $container_name, $e->get_error_text(), $error_code));
            }
        }
    }
    /**
     * Deletes a container.
     *
     *
     * @throws \RuntimeException if cannot delete the container
     */
    public function delete_container(string $container_name, Blob_Service_Options $options = null): void
    {
        $this->init();
        try {
            $this->blob_proxy->delete_container($container_name, $options);
        } catch (Service_Exception $e) {
            $error_code = $this->get_error_code_from_service_exception($e);
            if ($error_code !== self::ERROR_CONTAINER_NOT_FOUND) {
                throw new \RuntimeException(sprintf('Failed to delete the configured container "%s": %s (%s).', $container_name, $e->get_error_text(), $error_code), $e->get_code());
            }
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function read($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $blob = $this->blob_proxy->get_blob($container_name, $key);
            return stream_get_contents($blob->get_content_stream());
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('read key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function write($key, $content)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        if (class_exists(Create_Block_Blob_Options::class)) {
            $options = new Create_Block_Blob_Options();
        } else {
            // for microsoft/azure-storage < 1.0
            $options = new Create_Blob_Options();
        }
        if ($this->detect_content_type) {
            $content_type = $this->guess_content_type($content);
            $options->set_content_type($content_type);
        }
        $size = is_resource($content) ? Util\Size::from_resource($content) : Util\Size::from_content($content);
        try {
            if ($this->multi_container_mode) {
                $this->create_container($container_name);
            }
            $this->blob_proxy->create_block_blob($container_name, $key, $content, $options);
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('write content for key "%s"', $key), $container_name);
            return false;
        }
        return $size;
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function exists($key): bool
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        $list_blobs_options = new List_Blobs_Options();
        $list_blobs_options->set_prefix($key);
        try {
            $blobs_list = $this->blob_proxy->list_blobs($container_name, $list_blobs_options);
            foreach ($blobs_list->get_blobs() as $blob) {
                if ($key === $blob->get_name()) {
                    return true;
                }
            }
        } catch (Service_Exception $e) {
            $error_code = $this->get_error_code_from_service_exception($e);
            if ($this->multi_container_mode && self::ERROR_CONTAINER_NOT_FOUND === $error_code) {
                return false;
            }
            $this->fail_if_container_not_found($e, 'check if key exists', $container_name);
            throw new \RuntimeException(sprintf('Failed to check if key "%s" exists in container "%s": %s (%s).', $key, $container_name, $e->get_error_text(), $error_code), $e->get_code());
        }
        return false;
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     */
    public function keys()
    {
        $this->init();
        try {
            if ($this->multi_container_mode) {
                $containers_list = $this->blob_proxy->list_containers();
                return call_user_func_array('array_merge', array_map(function (Container $container) {
                    $container_name = $container->get_name();
                    return $this->fetch_blobs($container_name, $container_name);
                }, $containers_list->get_containers()));
            }
            return $this->fetch_blobs($this->container_name);
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, 'retrieve keys', $this->container_name);
            $error_code = $this->get_error_code_from_service_exception($e);
            throw new \RuntimeException(sprintf('Failed to list keys for the container "%s": %s (%s).', $this->container_name, $e->get_error_text(), $error_code), $e->get_code());
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function mtime($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $properties = $this->blob_proxy->get_blob_properties($container_name, $key);
            return $properties->get_properties()->get_last_modified()->get_timestamp();
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('read mtime for key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $properties = $this->blob_proxy->get_blob_properties($container_name, $key);
            return $properties->get_properties()->get_content_length();
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('read content length for key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function mime_type($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $properties = $this->blob_proxy->get_blob_properties($container_name, $key);
            return $properties->get_properties()->get_content_type();
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('read content mime type for key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $properties = $this->blob_proxy->get_blob_properties($container_name, $key);
            $checksum_base64 = $properties->get_properties()->get_content_md5();
            return \bin2hex(\base64_decode($checksum_base64, true));
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('read content MD5 for key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function delete($key): bool
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $this->blob_proxy->delete_blob($container_name, $key);
            return true;
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('delete key "%s"', $key), $container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function rename($source_key, $target_key): bool
    {
        $this->init();
        [$source_container_name, $source_key] = $this->tokenize_key($source_key);
        [$target_container_name, $target_key] = $this->tokenize_key($target_key);
        try {
            if ($this->multi_container_mode) {
                $this->create_container($target_container_name);
            }
            $this->blob_proxy->copy_blob($target_container_name, $target_key, $source_container_name, $source_key);
            $this->blob_proxy->delete_blob($source_container_name, $source_key);
            return true;
        } catch (Service_Exception $e) {
            $this->fail_if_container_not_found($e, sprintf('rename key "%s"', $source_key), $source_container_name);
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        // Windows Azure Blob Storage does not support directories
        return false;
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function set_metadata($key, $content): void
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $this->blob_proxy->set_blob_metadata($container_name, $key, $content);
        } catch (Service_Exception $e) {
            $error_code = $this->get_error_code_from_service_exception($e);
            throw new \RuntimeException(sprintf('Failed to set metadata for blob "%s" in container "%s": %s (%s).', $key, $container_name, $e->get_error_text(), $error_code), $e->get_code());
        }
    }
    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function get_metadata($key)
    {
        $this->init();
        [$container_name, $key] = $this->tokenize_key($key);
        try {
            $properties = $this->blob_proxy->get_blob_properties($container_name, $key);
            return $properties->get_metadata();
        } catch (Service_Exception $e) {
            $error_code = $this->get_error_code_from_service_exception($e);
            throw new \RuntimeException(sprintf('Failed to get metadata for blob "%s" in container "%s": %s (%s).', $key, $container_name, $e->get_error_text(), $error_code), $e->get_code());
        }
    }
    /**
     * Lazy initialization, automatically called when some method is called after construction.
     */
    protected function init()
    {
        if ($this->blob_proxy === null) {
            $this->blob_proxy = $this->blob_proxy_factory->create();
        }
    }
    /**
     * Throws a runtime exception if a give ServiceException derived from a "container not found" error.
     *
     * @param string           $containerName
     *
     * @throws \RuntimeException
     */
    protected function fail_if_container_not_found(Service_Exception $exception, string $action, $container_name)
    {
        $error_code = $this->get_error_code_from_service_exception($exception);
        if ($error_code === self::ERROR_CONTAINER_NOT_FOUND) {
            throw new \RuntimeException(sprintf('Failed to %s: container "%s" not found.', $action, $container_name), $exception->get_code());
        }
    }
    /**
     * Extracts the error code from a service exception.
     *
     *
     * @return string
     */
    protected function get_error_code_from_service_exception(Service_Exception $exception)
    {
        $xml = @simplexml_load_string($exception->get_response()->get_body());
        if ($xml && isset($xml->Code)) {
            return (string) $xml->Code;
        }
        return $exception->get_error_text();
    }
    /**
     * @param string|resource $content
     *
     * @return string
     */
    private function guess_content_type($content)
    {
        $file_info = new \finfo(FILEINFO_MIME_TYPE);
        if (is_resource($content)) {
            return $file_info->file(stream_get_meta_data($content)['uri']);
        }
        return $file_info->buffer($content);
    }
    /**
     * @param string $key
     *
     * @throws \InvalidArgumentException
     */
    private function tokenize_key($key): array
    {
        $container_name = $this->container_name;
        if (false === $this->multi_container_mode) {
            return [$container_name, $key];
        }
        if (false === $index = strpos($key, '/')) {
            throw new \InvalidArgumentException(sprintf('Failed to establish container name from key "%s", container name is required in multi-container mode', $key));
        }
        $container_name = substr($key, 0, $index);
        $key = substr($key, $index + 1);
        return [$container_name, $key];
    }
    /**
     * @param string $containerName
     *
     */
    private function fetch_blobs($container_name, $prefix = null): array
    {
        $blob_list = $this->blob_proxy->list_blobs($container_name);
        return array_map(function (Blob $blob) use ($prefix) {
            $name = $blob->get_name();
            if (null !== $prefix) {
                return $prefix . '/' . $name;
            }
            return $name;
        }, $blob_list->get_blobs());
    }
}