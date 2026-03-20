<?php

declare (strict_types=1);
namespace Gaufrette;

/**
 * Stream wrapper class for the Gaufrette filesystems.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Stream_Wrapper
{
    private static $filesystem_map;
    private $stream;
    /**
     * Defines the filesystem map.
     */
    public static function set_filesystem_map(Filesystem_Map $map): void
    {
        self::$filesystem_map = $map;
    }
    /**
     * Returns the filesystem map.
     *
     * @return FilesystemMap $map
     */
    public static function get_filesystem_map()
    {
        if (null === self::$filesystem_map) {
            self::$filesystem_map = self::create_filesystem_map();
        }
        return self::$filesystem_map;
    }
    /**
     * Registers the stream wrapper to handle the specified scheme.
     *
     * @param string $scheme Default is gaufrette
     */
    public static function register($scheme = 'gaufrette'): void
    {
        self::stream_wrapper_unregister($scheme);
        if (!self::stream_wrapper_register($scheme, self::class)) {
            throw new \RuntimeException(sprintf('Could not register stream wrapper class %s for scheme %s.', self::class, $scheme));
        }
    }
    protected static function create_filesystem_map(): \Gaufrette\Filesystem_Map
    {
        return new Filesystem_Map();
    }
    /**
     * @param string $scheme - protocol scheme
     */
    protected static function stream_wrapper_unregister($scheme)
    {
        if (in_array($scheme, stream_get_wrappers())) {
            return stream_wrapper_unregister($scheme);
        }
    }
    /**
     * @param string $scheme    - protocol scheme
     * @param string $className
     */
    protected static function stream_wrapper_register($scheme, $class_name): bool
    {
        return stream_wrapper_register($scheme, $class_name);
    }
    public function stream_open($path, $mode)
    {
        $this->stream = $this->create_stream($path);
        return $this->stream->open($this->create_stream_mode($mode));
    }
    /**
     * @param int $bytes
     *
     * @return mixed
     */
    public function stream_read($bytes)
    {
        if ($this->stream) {
            return $this->stream->read($bytes);
        }
        return false;
    }
    /**
     * @param string $data
     *
     * @return int
     */
    public function stream_write($data)
    {
        if ($this->stream) {
            return $this->stream->write($data);
        }
        return 0;
    }
    public function stream_close(): void
    {
        if ($this->stream) {
            $this->stream->close();
        }
    }
    /**
     * @return bool
     */
    public function stream_flush()
    {
        if ($this->stream) {
            return $this->stream->flush();
        }
        return false;
    }
    /**
     * @param int $offset
     * @param int $whence - one of values [SEEK_SET, SEEK_CUR, SEEK_END]
     *
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        if ($this->stream) {
            return $this->stream->seek($offset, $whence);
        }
        return false;
    }
    /**
     * @return mixed
     */
    public function stream_tell()
    {
        if ($this->stream) {
            return $this->stream->tell();
        }
        return false;
    }
    /**
     * @return bool
     */
    public function stream_eof()
    {
        if ($this->stream) {
            return $this->stream->eof();
        }
        return true;
    }
    /**
     * @return mixed
     */
    public function stream_stat()
    {
        if ($this->stream) {
            return $this->stream->stat();
        }
        return false;
    }
    /**
     * @param string $path
     * @param int    $flags
     *
     * @return mixed
     *
     * @todo handle $flags parameter
     */
    public function url_stat($path, $flags)
    {
        $stream = $this->create_stream($path);
        try {
            $stream->open($this->create_stream_mode('r+'));
        } catch (\RuntimeException $e) {
        }
        return $stream->stat();
    }
    /**
     * @param string $path
     *
     * @return mixed
     */
    public function unlink($path)
    {
        $stream = $this->create_stream($path);
        try {
            $stream->open($this->create_stream_mode('w+'));
        } catch (\RuntimeException $e) {
            return false;
        }
        return $stream->unlink();
    }
    /**
     * @return mixed
     */
    public function stream_cast($cast_as)
    {
        if ($this->stream) {
            return $this->stream->cast($cast_as);
        }
        return false;
    }
    protected function create_stream($path)
    {
        $parts = array_merge(['scheme' => null, 'host' => null, 'path' => null, 'query' => null, 'fragment' => null], parse_url($path) ?: []);
        $domain = $parts['host'];
        $key = !empty($parts['path']) ? substr($parts['path'], 1) : '';
        if (null !== $parts['query']) {
            $key .= '?' . $parts['query'];
        }
        if (null !== $parts['fragment']) {
            $key .= '#' . $parts['fragment'];
        }
        if (empty($domain) || empty($key)) {
            throw new \InvalidArgumentException(sprintf('The specified path (%s) is invalid.', $path));
        }
        return self::get_filesystem_map()->get($domain)->create_stream($key);
    }
    protected function create_stream_mode($mode): \Gaufrette\Stream_Mode
    {
        return new Stream_Mode($mode);
    }
}