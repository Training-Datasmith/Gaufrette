<?php

declare (strict_types=1);
namespace Gaufrette\Stream;

use Gaufrette\Stream;
use Gaufrette\Stream_Mode;
/**
 * Local stream.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class Local implements Stream
{
    private $path;
    private ?\Gaufrette\Stream_Mode $mode = null;
    private $file_handle;
    private $mkdir_mode;
    /**
     * @param string $path
     * @param int    $mkdirMode
     */
    public function __construct($path, $mkdir_mode = 0755)
    {
        $this->path = $path;
        $this->mkdir_mode = $mkdir_mode;
    }
    /**
     * {@inheritdoc}
     */
    public function open(Stream_Mode $mode): bool
    {
        $base_dir_path = \Gaufrette\Util\Path::dirname($this->path);
        if ($mode->allows_write() && !is_dir($base_dir_path)) {
            @mkdir($base_dir_path, $this->mkdir_mode, true);
        }
        try {
            $file_handle = @fopen($this->path, $mode->get_mode());
        } catch (\Exception $e) {
            $file_handle = false;
        }
        if (false === $file_handle) {
            throw new \RuntimeException(sprintf('File "%s" cannot be opened', $this->path));
        }
        $this->mode = $mode;
        $this->file_handle = $file_handle;
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function read($count)
    {
        if (!$this->file_handle) {
            return false;
        }
        if (false === $this->mode->allows_read()) {
            throw new \LogicException('The stream does not allow read.');
        }
        return fread($this->file_handle, $count);
    }
    /**
     * {@inheritdoc}
     */
    public function write($data)
    {
        if (!$this->file_handle) {
            return false;
        }
        if (false === $this->mode->allows_write()) {
            throw new \LogicException('The stream does not allow write.');
        }
        return fwrite($this->file_handle, $data);
    }
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if (!$this->file_handle) {
            return false;
        }
        $closed = fclose($this->file_handle);
        if ($closed) {
            $this->mode = null;
            $this->file_handle = null;
        }
        return $closed;
    }
    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        if ($this->file_handle) {
            return fflush($this->file_handle);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($this->file_handle) {
            return 0 === fseek($this->file_handle, $offset, $whence);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function tell()
    {
        if ($this->file_handle) {
            return ftell($this->file_handle);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function eof()
    {
        if ($this->file_handle) {
            return feof($this->file_handle);
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function stat()
    {
        if ($this->file_handle) {
            return fstat($this->file_handle);
        }
        if (!is_resource($this->file_handle) && is_dir($this->path)) {
            return stat($this->path);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function cast($cast_as)
    {
        if ($this->file_handle) {
            return $this->file_handle;
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function unlink()
    {
        if ($this->mode && $this->mode->implies_existing_content_deletion()) {
            return @unlink($this->path);
        }
        return false;
    }
}