<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Util;
use Zip_Archive;
/**
 * ZIP Archive adapter.
 *
 * @author Boris Guéry <guery.b@gmail.com>
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class Zip implements Adapter
{
    /**
     * @var string The zip archive full path
     */
    protected $zip_file;
    /**
     * @var ZipArchive
     */
    protected $zip_archive;
    public function __construct($zip_file)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException(sprintf('Unable to use %s as the ZIP extension is not available.', self::class));
        }
        $this->zip_file = $zip_file;
        $this->reinit_zip_archive();
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        if (false === $content = $this->zip_archive->get_from_name($key, 0)) {
            return false;
        }
        return $content;
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        if (!$this->zip_archive->add_from_string($key, $content)) {
            return false;
        }
        if (!$this->save()) {
            return false;
        }
        return Util\Size::from_content($content);
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        return (bool) $this->get_stat($key);
    }
    /**
     * {@inheritdoc}
     * @return mixed[]
     */
    public function keys(): array
    {
        $keys = [];
        for ($i = 0; $i < $this->zip_archive->num_files; ++$i) {
            $keys[$i] = $this->zip_archive->get_name_index($i);
        }
        return $keys;
    }
    /**
     * @todo implement
     *
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        $stat = $this->get_stat($key);
        return $stat['mtime'] ?? false;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        if (!$this->zip_archive->delete_name($key)) {
            return false;
        }
        return $this->save();
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key)
    {
        if (!$this->zip_archive->rename_name($source_key, $target_key)) {
            return false;
        }
        return $this->save();
    }
    /**
     * Returns the stat of a file in the zip archive
     *  (name, index, crc, mtime, compression size, compression method, filesize).
     *
     * @param $key
     *
     * @return array|bool
     */
    public function get_stat($key)
    {
        $stat = $this->zip_archive->stat_name($key);
        if (false === $stat) {
            return [];
        }
        return $stat;
    }
    public function __destruct()
    {
        if ($this->zip_archive) {
            try {
                $this->zip_archive->close();
            } catch (\Exception $e) {
            }
            unset($this->zip_archive);
        }
    }
    protected function reinit_zip_archive(): self
    {
        $this->zip_archive = new Zip_Archive();
        if (true !== $result_code = $this->zip_archive->open($this->zip_file, Zip_Archive::CREATE)) {
            switch ($result_code) {
                case Zip_Archive::ER_EXISTS:
                    $err_msg = 'File already exists.';
                    break;
                case Zip_Archive::ER_INCONS:
                    $err_msg = 'Zip archive inconsistent.';
                    break;
                case Zip_Archive::ER_INVAL:
                case Zip_Archive::ER_NOENT:
                    $err_msg = 'Invalid argument.';
                    break;
                case Zip_Archive::ER_MEMORY:
                    $err_msg = 'Malloc failure.';
                    break;
                case Zip_Archive::ER_NOZIP:
                    $err_msg = 'Not a zip archive.';
                    break;
                case Zip_Archive::ER_OPEN:
                    $err_msg = 'Can\'t open file.';
                    break;
                case Zip_Archive::ER_READ:
                    $err_msg = 'Read error.';
                    break;
                case Zip_Archive::ER_SEEK:
                    $err_msg = 'Seek error.';
                    break;
                default:
                    $err_msg = 'Unknown error.';
                    break;
            }
            throw new \RuntimeException(sprintf('%s', $err_msg));
        }
        return $this;
    }
    /**
     * Saves archive modifications and updates current ZipArchive instance.
     *
     * @throws \RuntimeException If file could not be saved
     */
    protected function save(): bool
    {
        // Close to save modification
        if (!$this->zip_archive->close()) {
            return false;
        }
        // Re-initialize to get updated version
        $this->reinit_zip_archive();
        return true;
    }
}