<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Stream;
use Gaufrette\Util;
/**
 * Adapter for the local filesystem.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Local implements Adapter, Stream_Factory, Checksum_Calculator, Size_Calculator, Mime_Type_Provider
{
    protected $directory;
    private $create;
    private $mode;
    /**
     * @param string $directory Directory where the filesystem is located
     * @param bool   $create    Whether to create the directory if it does not
     *                          exist (default FALSE)
     * @param int    $mode      Mode for mkdir
     *
     * @throws \RuntimeException if the specified directory does not exist and
     *                          could not be created
     */
    public function __construct($directory, $create = false, $mode = 0777)
    {
        $this->directory = Util\Path::normalize($directory);
        if (is_link($this->directory)) {
            $this->directory = realpath($this->directory);
        }
        $this->create = $create;
        $this->mode = $mode;
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function read($key)
    {
        if ($this->is_directory($key)) {
            return false;
        }
        return file_get_contents($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function write($key, $content)
    {
        $path = $this->compute_path($key);
        $this->ensure_directory_exists(\Gaufrette\Util\Path::dirname($path), true);
        return file_put_contents($path, $content);
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function rename($source_key, $target_key): bool
    {
        $target_path = $this->compute_path($target_key);
        $this->ensure_directory_exists(\Gaufrette\Util\Path::dirname($target_path), true);
        return rename($this->compute_path($source_key), $target_path);
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        return is_file($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     * @return mixed[]
     */
    public function keys(): array
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        try {
            $files = new \Recursive_Iterator_Iterator(new \Recursive_Directory_Iterator($this->directory, \Filesystem_Iterator::SKIP_DOTS | \Filesystem_Iterator::UNIX_PATHS), \Recursive_Iterator_Iterator::CHILD_FIRST);
        } catch (\Exception $e) {
            $files = new \Empty_Iterator();
        }
        $keys = [];
        foreach ($files as $file) {
            $keys[] = $this->compute_key($file);
        }
        sort($keys);
        return $keys;
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function mtime($key)
    {
        return filemtime($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * Can also delete a directory recursively when the given $key matches a
     * directory.
     */
    public function delete($key)
    {
        if ($this->is_directory($key)) {
            return $this->delete_directory($this->compute_path($key));
        }
        if ($this->exists($key)) {
            return unlink($this->compute_path($key));
        }
        return false;
    }
    /**
     * @param string $key
     *
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function is_directory($key): bool
    {
        return is_dir($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function create_stream($key): \Gaufrette\Stream\Local
    {
        return new Stream\Local($this->compute_path($key), $this->mode);
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function checksum($key)
    {
        return Util\Checksum::from_file($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function size($key)
    {
        return Util\Size::from_file($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function mime_type($key)
    {
        $file_info = new \finfo(FILEINFO_MIME_TYPE);
        return $file_info->file($this->compute_path($key));
    }
    /**
     * Computes the key from the specified path.
     *
     * @param $path
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function compute_key($path): string
    {
        $path = $this->normalize_path($path);
        return ltrim(substr($path, strlen($this->directory)), '/');
    }
    /**
     * Computes the path from the specified key.
     *
     * @param string $key The key which for to compute the path
     *
     * @return string A path
     *
     * @throws \InvalidArgumentException If the directory already exists
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \RuntimeException         If directory does not exists and cannot be created
     */
    protected function compute_path(string $key)
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        return $this->normalize_path($this->directory . '/' . $key);
    }
    /**
     * Normalizes the given path.
     *
     * @param string $path
     *
     * @return string
     * @throws \OutOfBoundsException If the computed path is out of the
     *                              directory
     */
    protected function normalize_path($path)
    {
        $path = Util\Path::normalize($path);
        if (0 !== strpos($path, (string) $this->directory)) {
            throw new \OutOfBoundsException(sprintf('The path "%s" is out of the filesystem.', $path));
        }
        return $path;
    }
    /**
     * Ensures the specified directory exists, creates it if it does not.
     *
     * @param string $directory Path of the directory to test
     * @param bool   $create    Whether to create the directory if it does
     *                          not exist
     *
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException if the directory does not exists and could not
     *                          be created
     */
    protected function ensure_directory_exists($directory, $create = false)
    {
        if (!is_dir($directory)) {
            if (!$create) {
                throw new \RuntimeException(sprintf('The directory "%s" does not exist.', $directory));
            }
            $this->create_directory($directory);
        }
    }
    /**
     * Creates the specified directory and its parents.
     *
     * @param string $directory Path of the directory to create
     *
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    protected function create_directory($directory)
    {
        if (!@mkdir($directory, $this->mode, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('The directory \'%s\' could not be created.', $directory));
        }
    }
    /**
     * @param string The directory's path to delete
     *
     * @throws \InvalidArgumentException When attempting to delete the root
     * directory of this adapter.
     *
     * @return bool Wheter the operation succeeded or not
     */
    private function delete_directory($directory)
    {
        if ($this->directory === $directory) {
            throw new \InvalidArgumentException(sprintf('Impossible to delete the root directory of this Local adapter ("%s").', $directory));
        }
        $status = true;
        if (file_exists($directory)) {
            $iterator = new \Recursive_Iterator_Iterator(new \Recursive_Directory_Iterator($directory, \Filesystem_Iterator::SKIP_DOTS | \Filesystem_Iterator::UNIX_PATHS), \Recursive_Iterator_Iterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->is_dir()) {
                    $status = $status && rmdir(strval($item));
                } else {
                    $status = $status && unlink(strval($item));
                }
            }
            $status = $status && rmdir($directory);
        }
        return $status;
    }
}