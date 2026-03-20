<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Aws\S3\S3Client;
use Gaufrette\Adapter;
use Gaufrette\Util;
/**
 * Amazon S3 adapter using the AWS SDK for PHP v2.x.
 *
 * @author  Michael Dowling <mtdowling@gmail.com>
 */
class Aws_S3 implements Adapter, Metadata_Supporter, List_Keys_Aware, Size_Calculator, Mime_Type_Provider
{
    /** @var S3Client */
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
    public function __construct(S3Client $service, $bucket, array $options = [], $detect_content_type = false)
    {
        if (!class_exists(S3Client::class)) {
            throw new \LogicException('You need to install package "aws/aws-sdk-php" to use this adapter');
        }
        $this->service = $service;
        $this->bucket = $bucket;
        $this->options = array_replace(['create' => false, 'directory' => '', 'acl' => 'private'], $options);
        $this->detect_content_type = $detect_content_type;
    }
    /**
     * {@inheritdoc}
     */
    public function set_metadata($key, $metadata): void
    {
        // BC with AmazonS3 adapter
        if (isset($metadata['contentType'])) {
            $metadata['ContentType'] = $metadata['contentType'];
            unset($metadata['contentType']);
        }
        $this->metadata[$key] = $metadata;
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
            $this->metadata[$key]['ContentType'] = $object->get('ContentType');
            return (string) $object->get('Body');
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
     */
    public function write($key, $content)
    {
        $this->ensure_bucket_exists();
        $options = $this->get_options($key, ['Body' => $content]);
        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as binary/octet-stream.
         */
        if (!isset($options['ContentType']) && $this->detect_content_type) {
            $options['ContentType'] = $this->guess_content_type($content);
        }
        try {
            $this->service->put_object($options);
            if (is_resource($content)) {
                return Util\Size::from_resource($content);
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
        return $this->service->does_object_exist($this->bucket, $this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $result = $this->service->head_object($this->get_options($key));
            return strtotime($result['LastModified']);
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        try {
            $result = $this->service->head_object($this->get_options($key));
            return $result['ContentLength'];
        } catch (\Exception $e) {
            return false;
        }
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
        $iter = $this->service->getIterator('ListObjects', $options);
        foreach ($iter as $file) {
            $keys[] = $this->compute_key($file['Key']);
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
    public function is_directory($key)
    {
        $result = $this->service->list_objects(['Bucket' => $this->bucket, 'Prefix' => rtrim($this->compute_path($key), '/') . '/', 'MaxKeys' => 1]);
        if (!isset($result['Contents'])) {
            return false;
        }
        if (is_countable($result['Contents'])) {
            return count($result['Contents']) > 0;
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
        if ($this->bucket_exists = $this->service->does_bucket_exist($this->bucket)) {
            return true;
        }
        if (!$this->options['create']) {
            throw new \RuntimeException(sprintf('The configured bucket "%s" does not exist.', $this->bucket));
        }
        $this->service->create_bucket(['Bucket' => $this->bucket, 'LocationConstraint' => $this->service->get_region()]);
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
    public function mime_type($key)
    {
        try {
            $result = $this->service->head_object($this->get_options($key));
            return $result['ContentType'];
        } catch (\Exception $e) {
            return false;
        }
    }
}