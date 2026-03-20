<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Google\Service\Exception as ServiceException;
use Google\Service\Storage;
use Google\Service\Storage\Bucket;
use Google\Service\Storage\Bucket_Iam_Configuration;
use Google\Service\Storage\Bucket_Iam_Configuration_Uniform_Bucket_Level_Access;
use Google\Service\Storage\Storage_Object;
use Guzzle_Http;
/**
 * Google Cloud Storage adapter using the Google APIs Client Library for PHP.
 *
 * @author  Patrik Karisch <patrik@karisch.guru>
 */
class Google_Cloud_Storage implements Adapter, Metadata_Supporter, List_Keys_Aware
{
    public const OPTION_CREATE_BUCKET_IF_NOT_EXISTS = 'create';
    public const OPTION_PROJECT_ID = 'project_id';
    public const OPTION_LOCATION = 'bucket_location';
    public const OPTION_STORAGE_CLASS = 'storage_class';
    protected \Google\Service\Storage $service;
    protected $bucket;
    protected array $options = [self::OPTION_CREATE_BUCKET_IF_NOT_EXISTS => false, self::OPTION_STORAGE_CLASS => 'STANDARD', 'directory' => '', 'acl' => 'private'];
    protected $bucket_exists;
    protected $metadata = [];
    protected $detect_content_type;
    /**
     * @param Storage $service           The storage service class with authenticated
     *                                                   client and full access scope
     * @param string                  $bucket            The bucket name
     * @param array                   $options           Options can be directory and acl
     * @param bool                    $detectContentType Whether to detect the content type or not
     */
    public function __construct(Storage $service, $bucket, array $options = [], $detect_content_type = false)
    {
        if (!class_exists(Storage::class)) {
            throw new \LogicException('You need to install package "google/apiclient" to use this adapter');
        }
        $this->service = $service;
        $this->bucket = $bucket;
        $this->options = array_replace($this->options, $options);
        $this->detect_content_type = $detect_content_type;
    }
    /**
     * @return array The actual options
     */
    public function get_options()
    {
        return $this->options;
    }
    /**
     * @param array $options The new options
     */
    public function set_options($options): void
    {
        $this->options = array_replace($this->options, $options);
    }
    /**
     * @return string The current bucket name
     */
    public function get_bucket()
    {
        return $this->bucket;
    }
    /**
     * Sets a new bucket name.
     *
     * @param string $bucket The new bucket name
     */
    public function set_bucket($bucket): void
    {
        $this->bucket_exists = null;
        $this->bucket = $bucket;
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensure_bucket_exists();
        $path = $this->compute_path($key);
        $object = $this->get_object_data($path);
        if ($object === false) {
            return false;
        }
        if (class_exists('Google_Http_Request')) {
            $request = new \Google_Http_Request($object->get_media_link());
            $this->service->get_client()->get_auth()->sign($request);
            $response = $this->service->get_client()->get_io()->execute_request($request);
            if ($response[2] == 200) {
                $this->set_metadata($key, $object->get_metadata());
                return $response[0];
            }
        } else {
            $http_client = new Guzzle_Http\Client();
            $http_client = $this->service->get_client()->authorize($http_client);
            $response = $http_client->request('GET', $object->get_media_link());
            if ($response->get_status_code() == 200) {
                $this->set_metadata($key, $object->get_metadata());
                return $response->get_body();
            }
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->ensure_bucket_exists();
        $path = $this->compute_path($key);
        $metadata = $this->get_metadata($key);
        $options = ['uploadType' => 'multipart', 'data' => $content];
        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as application/octet-stream.
         */
        if (!isset($metadata['ContentType']) && $this->detect_content_type) {
            $options['mimeType'] = $this->guess_content_type($content);
            unset($metadata['ContentType']);
        } elseif (isset($metadata['ContentType'])) {
            $options['mimeType'] = $metadata['ContentType'];
            unset($metadata['ContentType']);
        }
        $object = new Storage_Object();
        $object->name = $path;
        if (isset($metadata['ContentDisposition'])) {
            $object->set_content_disposition($metadata['ContentDisposition']);
            unset($metadata['ContentDisposition']);
        }
        if (isset($metadata['CacheControl'])) {
            $object->set_cache_control($metadata['CacheControl']);
            unset($metadata['CacheControl']);
        }
        if (isset($metadata['ContentLanguage'])) {
            $object->set_content_language($metadata['ContentLanguage']);
            unset($metadata['ContentLanguage']);
        }
        if (isset($metadata['ContentEncoding'])) {
            $object->set_content_encoding($metadata['ContentEncoding']);
            unset($metadata['ContentEncoding']);
        }
        $object->set_metadata($metadata);
        try {
            $object = $this->service->objects->insert($this->bucket, $object, $options);
            if ($this->options['acl'] == 'public') {
                $acl = new \Google_service_storage_object_Access_Control();
                $acl->set_entity('allUsers');
                $acl->set_role('READER');
                $this->service->object_access_controls->insert($this->bucket, $path, $acl);
            }
            return $object->get_size();
        } catch (Service_Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        $this->ensure_bucket_exists();
        $path = $this->compute_path($key);
        try {
            $this->service->objects->get($this->bucket, $path);
        } catch (Service_Exception $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        return $this->list_keys();
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        $this->ensure_bucket_exists();
        $path = $this->compute_path($key);
        $object = $this->get_object_data($path);
        return $object ? strtotime($object->get_updated()) : false;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        $this->ensure_bucket_exists();
        $path = $this->compute_path($key);
        try {
            $this->service->objects->delete($this->bucket, $path);
        } catch (Service_Exception $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        $this->ensure_bucket_exists();
        $source_path = $this->compute_path($source_key);
        $target_path = $this->compute_path($target_key);
        $object = $this->get_object_data($source_path);
        if ($object === false) {
            return false;
        }
        try {
            $this->service->objects->copy($this->bucket, $source_path, $this->bucket, $target_path, $object);
            $this->service->objects->delete($this->bucket, $source_path);
        } catch (Service_Exception $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        if ($this->exists($key . '/')) {
            return true;
        }
        return false;
    }
    /**
     * {@inheritdoc}
     * @return mixed[]
     */
    public function list_keys($prefix = ''): array
    {
        $this->ensure_bucket_exists();
        $options = [];
        if ((string) $prefix != '') {
            $options['prefix'] = $this->compute_path($prefix);
        } elseif (!empty($this->options['directory'])) {
            $options['prefix'] = $this->options['directory'];
        }
        $list = $this->service->objects->list_objects($this->bucket, $options);
        $keys = [];
        // FIXME: Temporary workaround for google/google-api-php-client#375
        $reflection_class = new \ReflectionClass('Google_Service_Storage_Objects');
        $reflection_property = $reflection_class->get_property('collection_key');
        $reflection_property->set_accessible(true);
        $reflection_property->set_value($list, 'items');
        /** @var StorageObject $object */
        foreach ($list as $object) {
            $keys[] = $object->name;
        }
        sort($keys);
        return $keys;
    }
    /**
     * {@inheritdoc}
     */
    public function set_metadata($key, $content): void
    {
        $path = $this->compute_path($key);
        $this->metadata[$path] = $content;
    }
    /**
     * {@inheritdoc}
     */
    public function get_metadata($key)
    {
        $path = $this->compute_path($key);
        return $this->metadata[$path] ?? [];
    }
    /**
     * Ensures the specified bucket exists.
     *
     * @throws \RuntimeException if the bucket does not exists
     */
    protected function ensure_bucket_exists()
    {
        if ($this->bucket_exists) {
            return;
        }
        try {
            $this->service->buckets->get($this->bucket);
            $this->bucket_exists = true;
            return;
        } catch (Service_Exception $e) {
            if ($this->options[self::OPTION_CREATE_BUCKET_IF_NOT_EXISTS]) {
                if (!isset($this->options[self::OPTION_PROJECT_ID])) {
                    throw new \RuntimeException(sprintf('Option "%s" missing, cannot create bucket', self::OPTION_PROJECT_ID));
                }
                if (!isset($this->options[self::OPTION_LOCATION])) {
                    throw new \RuntimeException(sprintf('Option "%s" missing, cannot create bucket', self::OPTION_LOCATION));
                }
                $bucket_iam_config_detail = new Bucket_Iam_Configuration_Uniform_Bucket_Level_Access();
                $bucket_iam_config_detail->set_enabled(true);
                $bucket_iam = new Bucket_Iam_Configuration();
                $bucket_iam->set_uniform_bucket_level_access($bucket_iam_config_detail);
                $bucket = new Bucket();
                $bucket->set_name($this->bucket);
                $bucket->set_location($this->options[self::OPTION_LOCATION]);
                $bucket->set_storage_class($this->options[self::OPTION_STORAGE_CLASS]);
                $bucket->set_iam_configuration($bucket_iam);
                $this->service->buckets->insert($this->options[self::OPTION_PROJECT_ID], $bucket);
                $this->bucket_exists = true;
                return;
            }
            $this->bucket_exists = false;
            throw new \RuntimeException(sprintf('The configured bucket "%s" does not exist.', $this->bucket));
        }
    }
    protected function compute_path($key)
    {
        if (empty($this->options['directory'])) {
            return $key;
        }
        return sprintf('%s/%s', $this->options['directory'], $key);
    }
    /**
     * @param string $path
     * @param array  $options
     *
     * @return bool|StorageObject
     */
    private function get_object_data($path, $options = [])
    {
        try {
            return $this->service->objects->get($this->bucket, $path, $options);
        } catch (Service_Exception $e) {
            return false;
        }
    }
    /**
     * @param string $content
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
}