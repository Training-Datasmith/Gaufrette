<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Util;
/**
 * In memory adapter.
 *
 * Stores some files in memory for test purposes
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class In_Memory implements Adapter, Mime_Type_Provider
{
    protected $files = [];
    /**
     * @param array $files An array of files
     */
    public function __construct(array $files = [])
    {
        $this->set_files($files);
    }
    /**
     * Defines the files.
     *
     * @param array $files An array of files
     */
    public function set_files(array $files): void
    {
        $this->files = [];
        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                $file = ['content' => $file];
            }
            $file = array_merge(['content' => null, 'mtime' => null], $file);
            $this->set_file($key, $file['content'], $file['mtime']);
        }
    }
    /**
     * Defines a file.
     *
     * @param string $key     The key
     * @param string $content The content
     * @param int    $mtime   The last modified time (automatically set to now if NULL)
     */
    public function set_file($key, $content = null, $mtime = null): void
    {
        if (null === $mtime) {
            $mtime = time();
        }
        $this->files[$key] = ['content' => (string) $content, 'mtime' => (int) $mtime];
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        return $this->files[$key]['content'];
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        $content = $this->read($source_key);
        $this->delete($source_key);
        return (bool) $this->write($target_key, $content);
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content, array $metadata = null)
    {
        $this->files[$key]['content'] = $content;
        $this->files[$key]['mtime'] = time();
        return Util\Size::from_content($content);
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        return array_key_exists($key, $this->files);
    }
    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return array_keys($this->files);
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        return $this->files[$key]['mtime'] ?? false;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        unset($this->files[$key]);
        clearstatcache();
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($path): bool
    {
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function mime_type($key)
    {
        $file_info = new \finfo(FILEINFO_MIME_TYPE);
        return $file_info->buffer($this->files[$key]['content']);
    }
}