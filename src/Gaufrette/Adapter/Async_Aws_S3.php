<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Async_Aws\Core\Configuration;
use Async_Aws\Simple_S3\Simple_S3client;
use Gaufrette\Adapter;
use Gaufrette\Util;
/**
 * Amazon S3 adapter using the AsyncAws.
 *
 * @author  Michael Dowling <mtdowling@gmail.com>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class Async_Aws_S3 implements Adapter, Metadata_Supporter, List_Keys_Aware, Size_Calculator, Mime_Type_Provider
{
    /** @var SimpleS3Client */
    protected $service;
    /** @var string */
    protected $bucket;
    protected array $options;
    /** @var bool */
    protected $bucket_exists;
    /** @var array */
    protected $metadata = [];
    /** @var bool */
    protected $detect_content_type;
    /**
     * @param string   $bucket
     * @param bool     $detectContentType
     */
    public function __construct(Simple_S3client $service, $bucket, array $options = [], $detect_content_type = false)
    {
        if (!class_exists(Simple_S3client::class)) {
            throw new \LogicException('You need to install package "async-aws/simple-s3" to use this adapter');
        }
        $this->service = $service;
        $this->bucket = $bucket;
        $this->options = array_replace(['create' => false, 'directory' => '', 'acl' => 'private'], $options);
        $this->detect_content_type = $detect_content_type;
    }
    /**
     * {@inheritdoc}
     */
    public function set_metadata($key, $content): void
    {
        // BC with AmazonS3 adapter
        if (isset($content['contentType'])) {
            $content['ContentType'] = $content['contentType'];
            unset($content['contentType']);
        }
        $this->metadata[$key] = $content;
    }
    /**
     * {@inheritdoc}
     */
    public function get_metadata($key)
    {
        return $this->metadata[$key] ?? [];
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensure_bucket_exists();
        $options = $this->get_options($key);
        try {
            // Get remote object
            $object = $this->service->get_object($options);
            // If there's no metadata array set up for this object, set it up
            if (!array_key_exists($key, $this->metadata) || !is_array($this->metadata[$key])) {
                $this->metadata[$key] = [];
            }
            // Make remote ContentType metadata available locally
            $this->metadata[$key]['ContentType'] = $object->get_content_type();
            return $object->get_body()->get_content_as_string();
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key)
    {
        $this->ensure_bucket_exists();
        $options = $this->get_options($target_key, ['CopySource' => $this->bucket . '/' . $this->compute_path($source_key)]);
        try {
            $this->service->copy_object(array_merge($options, $this->get_metadata($target_key)));
            return $this->delete($source_key);
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     * @param string|resource $content
     */
    public function write($key, $content)
    {
        $this->ensure_bucket_exists();
        $options = $this->get_options($key);
        unset($options['Bucket'], $options['Key']);
        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as binary/octet-stream.
         */
        if (!isset($options['ContentType']) && $this->detect_content_type) {
            $options['ContentType'] = $this->guess_content_type($content);
        }
        try {
            $this->service->upload($this->bucket, $this->compute_path($key), $content, $options);
            if (is_resource($content)) {
                return (int) Util\Size::from_resource($content);
            }
            return Util\Size::from_content($content);
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        return $this->service->has($this->bucket, $this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $result = $this->service->head_object($this->get_options($key));
            return $result->get_last_modified()->get_timestamp();
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function size($key): int
    {
        $result = $this->service->head_object($this->get_options($key));
        return (int) $result->get_content_length();
    }
    public function mime_type($key)
    {
        $result = $this->service->head_object($this->get_options($key));
        return $result->get_content_type();
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
     * @return mixed[]
     */
    public function list_keys($prefix = ''): array
    {
        $this->ensure_bucket_exists();
        $options = ['Bucket' => $this->bucket];
        if ((string) $prefix != '') {
            $options['Prefix'] = $this->compute_path($prefix);
        } elseif (!empty($this->options['directory'])) {
            $options['Prefix'] = $this->options['directory'];
        }
        $keys = [];
        $result = $this->service->list_objects_v2($options);
        foreach ($result->get_contents() as $file) {
            $keys[] = $this->compute_key($file->get_key());
        }
        return $keys;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        try {
            $this->service->delete_object($this->get_options($key));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        $result = $this->service->list_objects_v2(['Bucket' => $this->bucket, 'Prefix' => rtrim($this->compute_path($key), '/') . '/', 'MaxKeys' => 1]);
        foreach ($result->get_contents(true) as $file) {
            return true;
        }
        return false;
    }
    /**
     * Ensures the specified bucket exists. If the bucket does not exists
     * and the create option is set to true, it will try to create the
     * bucket. The bucket is created using the same region as the supplied
     * client object.
     *
     * @throws \RuntimeException if the bucket does not exists or could not be
     *                           created
     */
    protected function ensure_bucket_exists(): bool
    {
        if ($this->bucket_exists) {
            return true;
        }
        if ($this->bucket_exists = $this->service->bucket_exists(['Bucket' => $this->bucket])->is_success()) {
            return true;
        }
        if (!$this->options['create']) {
            throw new \RuntimeException(sprintf('The configured bucket "%s" does not exist.', $this->bucket));
        }
        $this->service->create_bucket(['Bucket' => $this->bucket, 'CreateBucketConfiguration' => ['LocationConstraint' => $this->service->get_configuration()->get(Configuration::OPTION_REGION)]]);
        $this->bucket_exists = true;
        return true;
    }
    /**
     * @return mixed[]
     */
    protected function get_options($key, array $options = []): array
    {
        $options['ACL'] = $this->options['acl'];
        $options['Bucket'] = $this->bucket;
        $options['Key'] = $this->compute_path($key);
        /*
         * Merge global options for adapter, which are set in the constructor, with metadata.
         * Metadata will override global options.
         */
        $options = array_merge($this->options, $options, $this->get_metadata($key));
        return $options;
    }
    protected function compute_path($key)
    {
        if (empty($this->options['directory'])) {
            return $key;
        }
        return sprintf('%s/%s', $this->options['directory'], $key);
    }
    /**
     * Computes the key from the specified path.
     *
     * @param string $path
     *
     * return string
     */
    protected function compute_key($path): string
    {
        return ltrim(substr($path, strlen($this->options['directory'])), '/');
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
}