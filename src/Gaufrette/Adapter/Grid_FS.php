<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Mongo_Db\BSON\Regex;
use Mongo_Db\Grid_Fs\Bucket;
use Mongo_Db\Grid_Fs\Exception\File_Not_Found_Exception;
/**
 * Adapter for the GridFS filesystem on MongoDB database.
 *
 * @author Tomi Saarinen <tomi.saarinen@rohea.com>
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Grid_Fs implements Adapter, Checksum_Calculator, Metadata_Supporter, List_Keys_Aware, Size_Calculator
{
    /** @var array */
    private $metadata = [];
    /** @var Bucket */
    private $bucket;
    public function __construct(Bucket $bucket)
    {
        if (!class_exists(Bucket::class)) {
            throw new \LogicException('You need to install package "mongodb/mongodb" to use this adapter');
        }
        $this->bucket = $bucket;
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        try {
            $stream = $this->bucket->open_download_stream_by_name($key);
        } catch (File_Not_Found_Exception $e) {
            return false;
        }
        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $stream = $this->bucket->open_upload_stream($key, ['metadata' => $this->get_metadata($key)]);
        try {
            return fwrite($stream, $content);
        } finally {
            fclose($stream);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        $metadata = $this->get_metadata($source_key);
        $writable = $this->bucket->open_upload_stream($target_key, ['metadata' => $metadata]);
        try {
            $this->bucket->download_to_stream_by_name($source_key, $writable);
            $this->set_metadata($target_key, $metadata);
            $this->delete($source_key);
        } catch (File_Not_Found_Exception $e) {
            return false;
        } finally {
            fclose($writable);
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        return (bool) $this->bucket->find_one(['filename' => $key]);
    }
    /**
     * {@inheritdoc}
     * @return mixed[]
     */
    public function keys(): array
    {
        $keys = [];
        $cursor = $this->bucket->find([], ['projection' => ['filename' => 1]]);
        foreach ($cursor as $file) {
            $keys[] = $file['filename'];
        }
        return $keys;
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        $file = $this->bucket->find_one(['filename' => $key], ['projection' => ['uploadDate' => 1]]);
        return $file ? (int) $file['uploadDate']->to_date_time()->format('U') : false;
    }
    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        $file = $this->bucket->find_one(['filename' => $key], ['projection' => ['md5' => 1]]);
        return $file ? $file['md5'] : false;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        if (null === $file = $this->bucket->find_one(['filename' => $key], ['projection' => ['_id' => 1]])) {
            return false;
        }
        $this->bucket->delete($file['_id']);
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function set_metadata($key, $metadata): void
    {
        $this->metadata[$key] = $metadata;
    }
    /**
     * {@inheritdoc}
     */
    public function get_metadata($key)
    {
        if (isset($this->metadata[$key])) {
            return $this->metadata[$key];
        }
        $meta = $this->bucket->find_one(['filename' => $key], ['projection' => ['metadata' => 1, '_id' => 0]]);
        if ($meta === null || !isset($meta['metadata'])) {
            return [];
        }
        $this->metadata[$key] = iterator_to_array($meta['metadata']);
        return $this->metadata[$key];
    }
    /**
     * {@inheritdoc}
     * @return mixed[]
     */
    public function list_keys($prefix = ''): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return ['dirs' => [], 'keys' => $this->keys()];
        }
        $regex = new Regex(sprintf('^%s', $prefix), '');
        $files = $this->bucket->find(['filename' => $regex], ['projection' => ['filename' => 1]]);
        $result = ['dirs' => [], 'keys' => []];
        foreach ($files as $file) {
            $result['keys'][] = $file['filename'];
        }
        return $result;
    }
    public function size($key)
    {
        if (!$this->exists($key)) {
            return false;
        }
        $size = $this->bucket->find_one(['filename' => $key], ['projection' => ['length' => 1, '_id' => 0]]);
        if (!isset($size['length'])) {
            return false;
        }
        return $size['length'];
    }
}