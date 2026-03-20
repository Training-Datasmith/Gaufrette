<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\File;
use Gaufrette\Filesystem;
use phpseclib\Net\SFTP as SecLibSFTP;
class Phpseclib_Sftp implements Adapter, File_Factory, List_Keys_Aware
{
    protected \phpseclib\Net\SFTP $sftp;
    protected $directory;
    protected $create;
    protected $initialized = false;
    /**
     * @param SecLibSFTP  $sftp      An Sftp instance
     * @param string      $directory The distant directory
     * @param bool        $create    Whether to create the remote directory if it
     *                               does not exist
     */
    public function __construct(Sec_Lib_Sftp $sftp, $directory = null, $create = false)
    {
        if (!class_exists(Sec_Lib_Sftp::class)) {
            throw new \LogicException('You need to install package "phpseclib/phpseclib" to use this adapter');
        }
        $this->sftp = $sftp;
        $this->directory = $directory;
        $this->create = $create;
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        return $this->sftp->get($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key)
    {
        $this->initialize();
        $source_path = $this->compute_path($source_key);
        $target_path = $this->compute_path($target_key);
        $this->ensure_directory_exists(\Gaufrette\Util\Path::dirname($target_path), true);
        return $this->sftp->rename($source_path, $target_path);
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->initialize();
        $path = $this->compute_path($key);
        $this->ensure_directory_exists(\Gaufrette\Util\Path::dirname($path), true);
        if ($this->sftp->put($path, $content)) {
            return $this->sftp->size($path);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        $this->initialize();
        return false !== $this->sftp->stat($this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        $this->initialize();
        $pwd = $this->sftp->pwd();
        if ($this->sftp->chdir($this->compute_path($key))) {
            $this->sftp->chdir($pwd);
            return true;
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        $keys = $this->fetch_keys();
        return $keys['keys'];
    }
    /**
     * {@inheritdoc}
     */
    public function list_keys($prefix = '')
    {
        preg_match('/(.*?)[^\/]*$/', $prefix, $match);
        $directory = rtrim($match[1], '/');
        $keys = $this->fetch_keys($directory, false);
        if ($directory === $prefix) {
            return $keys;
        }
        $filtered_keys = [];
        foreach (['keys', 'dirs'] as $hash) {
            $filtered_keys[$hash] = [];
            foreach ($keys[$hash] as $key) {
                if (0 === strpos($key, $prefix)) {
                    $filtered_keys[$hash][] = $key;
                }
            }
        }
        return $filtered_keys;
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        $this->initialize();
        $stat = $this->sftp->stat($this->compute_path($key));
        return $stat['mtime'] ?? false;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        return $this->sftp->delete($this->compute_path($key), false);
    }
    /**
     * {@inheritdoc}
     */
    public function create_file($key, Filesystem $filesystem): \Gaufrette\File
    {
        $file = new File($key, $filesystem);
        $stat = $this->sftp->stat($this->compute_path($key));
        if (isset($stat['size'])) {
            $file->set_size($stat['size']);
        }
        return $file;
    }
    /**
     * Performs the adapter's initialization.
     *
     * It will ensure the root directory exists
     */
    protected function initialize()
    {
        if ($this->initialized) {
            return;
        }
        $this->ensure_directory_exists($this->directory, $this->create);
        $this->initialized = true;
    }
    protected function ensure_directory_exists($directory, $create)
    {
        $pwd = $this->sftp->pwd();
        if ($this->sftp->chdir($directory)) {
            $this->sftp->chdir($pwd);
        } elseif ($create) {
            if (!$this->sftp->mkdir($directory, 0777, true)) {
                throw new \RuntimeException(sprintf('The directory \'%s\' does not exist and could not be created (%s).', $this->directory, $this->sftp->get_last_sftp_error()));
            }
        } else {
            throw new \RuntimeException(sprintf('The directory \'%s\' does not exist.', $this->directory));
        }
    }
    protected function compute_path($key): string
    {
        return $this->directory . '/' . ltrim($key, '/');
    }
    /**
     * @return mixed[]
     */
    protected function fetch_keys(string $directory = '', $only_keys = true): array
    {
        $keys = ['keys' => [], 'dirs' => []];
        $computed_path = $this->compute_path($directory);
        if (!$this->sftp->file_exists($computed_path)) {
            return $keys;
        }
        $list = $this->sftp->rawlist($computed_path);
        foreach ((array) $list as $filename => $stat) {
            if ('.' === $filename) {
                continue;
            }
            if ('..' === $filename) {
                continue;
            }
            $path = ltrim($directory . '/' . $filename, '/');
            if (isset($stat['type']) && $stat['type'] === NET_SFTP_TYPE_DIRECTORY) {
                $keys['dirs'][] = $path;
            } else {
                $keys['keys'][] = $path;
            }
        }
        $dirs = $keys['dirs'];
        if ($only_keys && !empty($dirs)) {
            $keys['keys'] = array_merge($keys['keys'], $dirs);
            $keys['dirs'] = [];
        }
        foreach ($dirs as $dir) {
            $keys = array_merge_recursive($keys, $this->fetch_keys($dir, $only_keys));
        }
        return $keys;
    }
}