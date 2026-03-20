<?php

declare (strict_types=1);
namespace Gaufrette;

use Gaufrette\Adapter\List_Keys_Aware;
/**
 * A filesystem is used to store and retrieve files.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Filesystem implements Filesystem_Interface
{
    protected \Gaufrette\Adapter $adapter;
    /**
     * Contains File objects created with $this->createFile() method.
     *
     * @var array
     */
    protected $file_register = [];
    /**
     * Creates a Filesystem backed by the given adapter.
     *
     * @param Adapter $adapter A fully configured Adapter instance (Local, S3, Azure, …).
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Returns the underlying adapter for this filesystem.
     *
     * @return Adapter The adapter instance passed at construction time.
     */
    public function get_adapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Returns whether a file exists at the given key.
     *
     * @param string $key The file key (path) to check.
     *
     * @return bool `true` if the file exists in the backend storage.
     *
     * @throws \InvalidArgumentException If $key is empty or invalid.
     */
    public function has($key)
    {
        self::assert_valid_key($key);
        return $this->adapter->exists($key);
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        self::assert_valid_key($source_key);
        self::assert_valid_key($target_key);
        $this->assert_has_file($source_key);
        if ($this->has($target_key)) {
            throw new Exception\Unexpected_File($target_key);
        }
        if (!$this->adapter->rename($source_key, $target_key)) {
            throw new \RuntimeException(sprintf('Could not rename the "%s" key to "%s".', $source_key, $target_key));
        }
        if ($this->is_file_in_register($source_key)) {
            $this->file_register[$target_key] = $this->file_register[$source_key];
            unset($this->file_register[$source_key]);
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function get($key, $create = false)
    {
        self::assert_valid_key($key);
        if (!$create) {
            $this->assert_has_file($key);
        }
        return $this->create_file($key);
    }
    /**
     * Writes content to the given key, creating or overwriting the file.
     *
     * @param string $key       The target file key (path).
     * @param string $content   The raw content to write.
     * @param bool   $overwrite Whether to allow overwriting an existing file (default: false).
     *
     * @return int The number of bytes written.
     *
     * @throws Exception\File_Already_Exists If the file already exists and $overwrite is false.
     * @throws \RuntimeException             If the adapter write operation fails.
     * @throws \InvalidArgumentException     If $key is empty or invalid.
     */
    public function write($key, $content, $overwrite = false)
    {
        self::assert_valid_key($key);
        if (!$overwrite && $this->has($key)) {
            throw new Exception\File_Already_Exists($key);
        }
        $num_bytes = $this->adapter->write($key, $content);
        if (false === $num_bytes) {
            throw new \RuntimeException(sprintf('Could not write the "%s" key content.', $key));
        }
        return $num_bytes;
    }
    /**
     * Reads and returns the full content of a file.
     *
     * @param string $key The file key (path) to read.
     *
     * @return string The raw file contents.
     *
     * @throws Exception\File_Not_Found If no file exists at $key.
     * @throws \RuntimeException        If the adapter read operation fails.
     * @throws \InvalidArgumentException If $key is empty or invalid.
     */
    public function read($key)
    {
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        $content = $this->adapter->read($key);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read the "%s" key content.', $key));
        }
        return $content;
    }
    /**
     * Deletes the file at the given key.
     *
     * @param string $key The file key (path) to delete.
     *
     * @return bool `true` on success (always — throws on failure).
     *
     * @throws Exception\File_Not_Found If no file exists at $key.
     * @throws \RuntimeException        If the adapter delete operation fails.
     * @throws \InvalidArgumentException If $key is empty or invalid.
     */
    public function delete($key): bool
    {
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        if ($this->adapter->delete($key)) {
            $this->remove_from_register($key);
            return true;
        }
        throw new \RuntimeException(sprintf('Could not remove the "%s" key.', $key));
    }
    /**
     * Returns all keys (file paths) known to this filesystem.
     *
     * @return string[] An unordered list of all file keys in the storage backend.
     *
     * @complexity O(n) — requires listing all entries in the backend.
     */
    public function keys()
    {
        return $this->adapter->keys();
    }
    /**
     * {@inheritdoc}
     */
    public function list_keys($prefix = '')
    {
        if ($this->adapter instanceof List_Keys_Aware) {
            return $this->adapter->list_keys($prefix);
        }
        $dirs = [];
        $keys = [];
        foreach ($this->keys() as $key) {
            if (empty($prefix) || 0 === strpos($key, $prefix)) {
                if ($this->adapter->is_directory($key)) {
                    $dirs[] = $key;
                } else {
                    $keys[] = $key;
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
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        return $this->adapter->mtime($key);
    }
    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        if ($this->adapter instanceof Adapter\Checksum_Calculator) {
            return $this->adapter->checksum($key);
        }
        return Util\Checksum::from_content($this->read($key));
    }
    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        if ($this->adapter instanceof Adapter\Size_Calculator) {
            return $this->adapter->size($key);
        }
        return Util\Size::from_content($this->read($key));
    }
    /**
     * {@inheritdoc}
     */
    public function create_stream($key)
    {
        self::assert_valid_key($key);
        if ($this->adapter instanceof Adapter\Stream_Factory) {
            return $this->adapter->create_stream($key);
        }
        return new Stream\In_Memory_Buffer($this, $key);
    }
    /**
     * {@inheritdoc}
     */
    public function create_file($key)
    {
        self::assert_valid_key($key);
        if (false === $this->is_file_in_register($key)) {
            if ($this->adapter instanceof Adapter\File_Factory) {
                $this->file_register[$key] = $this->adapter->create_file($key, $this);
            } else {
                $this->file_register[$key] = new File($key, $this);
            }
        }
        return $this->file_register[$key];
    }
    /**
     * {@inheritdoc}
     */
    public function mime_type($key)
    {
        self::assert_valid_key($key);
        $this->assert_has_file($key);
        if ($this->adapter instanceof Adapter\Mime_Type_Provider) {
            return $this->adapter->mime_type($key);
        }
        throw new \LogicException(sprintf('Adapter "%s" cannot provide MIME type', get_class($this->adapter)));
    }
    /**
     * Checks if matching file by given key exists in the filesystem.
     *
     * Key must be non empty string, otherwise it will throw Exception\FileNotFound
     * {@see http://php.net/manual/en/function.empty.php}
     *
     * @param string $key
     *
     * @throws Exception\FileNotFound when sourceKey does not exist
     */
    private function assert_has_file($key): void
    {
        if (!$this->has($key)) {
            throw new Exception\File_Not_Found($key);
        }
    }
    /**
     * Checks if matching File object by given key exists in the fileRegister.
     *
     * @param string $key
     */
    private function is_file_in_register($key): bool
    {
        return array_key_exists($key, $this->file_register);
    }
    /**
     * Clear files register.
     */
    public function clear_file_register(): void
    {
        $this->file_register = [];
    }
    /**
     * Removes File object from register.
     *
     * @param string $key
     */
    public function remove_from_register($key): void
    {
        if ($this->is_file_in_register($key)) {
            unset($this->file_register[$key]);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key)
    {
        return $this->adapter->is_directory($key);
    }
    /**
     * @param string $key
     *
     * @throws \InvalidArgumentException Given $key should not be empty
     */
    private static function assert_valid_key($key): void
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Object path is empty.');
        }
    }
}