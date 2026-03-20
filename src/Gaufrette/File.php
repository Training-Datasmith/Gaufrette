<?php

declare (strict_types=1);
namespace Gaufrette;

use Gaufrette\Adapter\Metadata_Supporter;
use Gaufrette\Exception\File_Not_Found;
/**
 * Points to a file in a filesystem.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class File
{
    protected $key;
    protected \Gaufrette\Filesystem_Interface $filesystem;
    /**
     * Content variable is lazy. It will not be read from filesystem until it's requested first time.
     *
     * @var mixed content
     */
    protected $content;
    /**
     * @var array metadata in associative array. Only for adapters that support metadata
     */
    protected $metadata;
    /**
     * Human readable filename (usually the end of the key).
     *
     * @var string name
     */
    protected $name;
    /**
     * File size in bytes.
     *
     * @var int size
     */
    protected $size = 0;
    /**
     * File date modified.
     *
     * @var int mtime
     */
    protected $mtime;
    /**
     * @param string     $key
     */
    public function __construct($key, Filesystem_Interface $filesystem)
    {
        $this->key = $key;
        $this->name = $key;
        $this->filesystem = $filesystem;
    }
    /**
     * Returns the key.
     *
     * @return string
     */
    public function get_key()
    {
        return $this->key;
    }
    /**
     * Returns the content.
     *
     * @throws FileNotFound
     *
     * @param array $metadata optional metadata which should be set when read
     *
     * @return string
     */
    public function get_content(array $metadata = [])
    {
        if (isset($this->content)) {
            return $this->content;
        }
        $this->set_metadata($metadata);
        return $this->content = $this->filesystem->read($this->key);
    }
    /**
     * @return string name of the file
     */
    public function get_name()
    {
        return $this->name;
    }
    /**
     * @return int size of the file
     */
    public function get_size()
    {
        if ($this->size) {
            return $this->size;
        }
        try {
            return $this->size = $this->filesystem->size($this->get_key());
        } catch (File_Not_Found $exception) {
        }
        return 0;
    }
    /**
     * Returns the file modified time.
     *
     * @return int
     */
    public function get_mtime()
    {
        return $this->mtime = $this->filesystem->mtime($this->key);
    }
    /**
     * @param int $size size of the file
     */
    public function set_size($size): void
    {
        $this->size = $size;
    }
    /**
     * Sets the content.
     *
     * @param string $content
     * @param array  $metadata optional metadata which should be send when write
     *
     * @return int The number of bytes that were written into the file, or
     *             FALSE on failure
     */
    public function set_content($content, array $metadata = [])
    {
        $this->content = $content;
        $this->set_metadata($metadata);
        return $this->size = $this->filesystem->write($this->key, $this->content, true);
    }
    /**
     * @param string $name name of the file
     */
    public function set_name($name): void
    {
        $this->name = $name;
    }
    /**
     * Indicates whether the file exists in the filesystem.
     *
     * @return bool
     */
    public function exists()
    {
        return $this->filesystem->has($this->key);
    }
    /**
     * Deletes the file from the filesystem.
     *
     * @throws FileNotFound
     * @throws \RuntimeException when cannot delete file
     *
     * @param array $metadata optional metadata which should be send when write
     *
     * @return bool TRUE on success
     */
    public function delete(array $metadata = [])
    {
        $this->set_metadata($metadata);
        return $this->filesystem->delete($this->key);
    }
    /**
     * Creates a new file stream instance of the file.
     *
     * @return Stream
     */
    public function create_stream()
    {
        return $this->filesystem->create_stream($this->key);
    }
    /**
     * Rename the file and move it to its new location.
     *
     * @param string $newKey
     */
    public function rename($new_key): void
    {
        $this->filesystem->rename($this->key, $new_key);
        $this->key = $new_key;
    }
    /**
     * Sets the metadata array to be stored in adapters that can support it.
     *
     *
     */
    protected function set_metadata(array $metadata): bool
    {
        if ($metadata && $this->supports_metadata()) {
            $this->filesystem->get_adapter()->set_metadata($this->key, $metadata);
            return true;
        }
        return false;
    }
    private function supports_metadata(): bool
    {
        return $this->filesystem->get_adapter() instanceof Metadata_Supporter;
    }
}