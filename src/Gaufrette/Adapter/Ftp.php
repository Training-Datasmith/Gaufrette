<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\File;
use Gaufrette\Filesystem;
/**
 * Ftp adapter.
 *
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class Ftp implements Adapter, File_Factory, List_Keys_Aware, Size_Calculator
{
    /** @var null|resource|\FTP\Connection */
    protected $connection;
    protected string $directory;
    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $passive;
    protected $create;
    protected $mode;
    protected $ssl;
    protected $timeout;
    protected $file_data = [];
    protected $utf8;
    /**
     * @param string $directory The directory to use in the ftp server
     * @param string $host      The host of the ftp server
     * @param array  $options   The options like port, username, password, passive, create, mode
     */
    public function __construct($directory, $host, array $options = [])
    {
        if (!extension_loaded('ftp')) {
            throw new \RuntimeException('Unable to use Gaufrette\Adapter\Ftp as the FTP extension is not available.');
        }
        $this->directory = (string) $directory;
        $this->host = $host;
        $this->port = $options['port'] ?? 21;
        $this->username = $options['username'] ?? null;
        $this->password = $options['password'] ?? null;
        $this->passive = $options['passive'] ?? false;
        $this->create = $options['create'] ?? false;
        $this->mode = $options['mode'] ?? FTP_BINARY;
        $this->ssl = $options['ssl'] ?? false;
        $this->timeout = $options['timeout'] ?? 90;
        $this->utf8 = $options['utf8'] ?? false;
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $temp = fopen('php://temp', 'r+');
        if (!ftp_fget($this->get_connection(), $temp, $this->compute_path($key), $this->mode)) {
            return false;
        }
        rewind($temp);
        $contents = stream_get_contents($temp);
        fclose($temp);
        return $contents;
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $path = $this->compute_path($key);
        $directory = \Gaufrette\Util\Path::dirname($path);
        $this->ensure_directory_exists($directory, true);
        $temp = fopen('php://temp', 'r+');
        $size = fwrite($temp, $content);
        rewind($temp);
        if (!ftp_fput($this->get_connection(), $path, $temp, $this->mode)) {
            fclose($temp);
            return false;
        }
        fclose($temp);
        return $size;
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $source_path = $this->compute_path($source_key);
        $target_path = $this->compute_path($target_key);
        $this->ensure_directory_exists(\Gaufrette\Util\Path::dirname($target_path), true);
        return ftp_rename($this->get_connection(), $source_path, $target_path);
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $file = $this->compute_path($key);
        $lines = ftp_rawlist($this->get_connection(), '-al ' . \Gaufrette\Util\Path::dirname($file));
        if (false === $lines) {
            return false;
        }
        $pattern = '{(?<!->) ' . preg_quote(basename($file)) . '( -> |$)}m';
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $keys = $this->fetch_keys();
        return $keys['keys'];
    }
    /**
     * {@inheritdoc}
     */
    public function list_keys($prefix = '')
    {
        $this->ensure_directory_exists($this->directory, $this->create);
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
    public function mtime($key): int
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $mtime = ftp_mdtm($this->get_connection(), $this->compute_path($key));
        // the server does not support this function
        if (-1 === $mtime) {
            throw new \RuntimeException('Server does not support ftp_mdtm function.');
        }
        return $mtime;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        if ($this->is_directory($key)) {
            return ftp_rmdir($this->get_connection(), $this->compute_path($key));
        }
        return ftp_delete($this->get_connection(), $this->compute_path($key));
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key)
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        return $this->is_dir($this->compute_path($key));
    }
    /**
     * Lists files from the specified directory. If a pattern is
     * specified, it only returns files matching it.
     *
     * @param string $directory The path of the directory to list from
     *
     * @return array An array of keys and dirs
     */
    public function list_directory($directory = ''): array
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $directory = preg_replace('/^[\/]*([^\/].*)$/', '/$1', $directory);
        $items = $this->parse_rawlist(ftp_rawlist($this->get_connection(), '-al ' . $this->directory . $directory) ?: []);
        $file_data = $dirs = [];
        foreach ($items as $item_data) {
            if ('..' === $item_data['name']) {
                continue;
            }
            if ('.' === $item_data['name']) {
                continue;
            }
            $item = ['name' => $item_data['name'], 'path' => trim(($directory ? $directory . '/' : '') . $item_data['name'], '/'), 'time' => $item_data['time'], 'size' => $item_data['size']];
            if ('-' === substr($item_data['perms'], 0, 1)) {
                $file_data[$item['path']] = $item;
            } elseif ('d' === substr($item_data['perms'], 0, 1)) {
                $dirs[] = $item['path'];
            }
        }
        $this->file_data = array_merge($file_data, $this->file_data);
        return ['keys' => array_keys($file_data), 'dirs' => $dirs];
    }
    /**
     * {@inheritdoc}
     */
    public function create_file($key, Filesystem $filesystem): \Gaufrette\File
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        $file = new File($key, $filesystem);
        if (!array_key_exists($key, $this->file_data)) {
            $dirname = \Gaufrette\Util\Path::dirname($key);
            $directory = $dirname == '.' ? '' : $dirname;
            $this->list_directory($directory);
        }
        if (isset($this->file_data[$key])) {
            $file_data = $this->file_data[$key];
            $file->set_name($file_data['name']);
            $file->set_size($file_data['size']);
        }
        return $file;
    }
    /**
     * @param string $key
     *
     *
     * @throws \RuntimeException
     */
    public function size($key): int
    {
        $this->ensure_directory_exists($this->directory, $this->create);
        if (-1 === $size = ftp_size($this->connection, $key)) {
            throw new \RuntimeException(sprintf('Unable to fetch the size of "%s".', $key));
        }
        return $size;
    }
    /**
     * Ensures the specified directory exists. If it does not, and the create
     * parameter is set to TRUE, it tries to create it.
     *
     * @param string $directory
     * @param bool   $create    Whether to create the directory if it does not
     *                          exist
     *
     * @throws RuntimeException if the directory does not exist and could not
     *                          be created
     */
    protected function ensure_directory_exists($directory, $create = false)
    {
        if (!$this->is_dir($directory)) {
            if (!$create) {
                throw new \RuntimeException(sprintf('The directory \'%s\' does not exist.', $directory));
            }
            $this->create_directory($directory);
        }
    }
    /**
     * Creates the specified directory and its parent directories.
     *
     * @param string $directory Directory to create
     *
     * @throws RuntimeException if the directory could not be created
     */
    protected function create_directory(string $directory)
    {
        // create parent directory if needed
        $parent = \Gaufrette\Util\Path::dirname($directory);
        if (!$this->is_dir($parent)) {
            $this->create_directory($parent);
        }
        // create the specified directory
        $created = ftp_mkdir($this->get_connection(), $directory);
        if (false === $created) {
            throw new \RuntimeException(sprintf('Could not create the \'%s\' directory.', $directory));
        }
    }
    /**
     * @param string $directory - full directory path
     */
    private function is_dir($directory): bool
    {
        if ('/' === $directory) {
            return true;
        }
        if (!@ftp_chdir($this->get_connection(), $directory)) {
            return false;
        }
        // change directory again to return in the base directory
        ftp_chdir($this->get_connection(), $this->directory);
        return true;
    }
    /**
     * @return mixed[]
     */
    private function fetch_keys($directory = '', $only_keys = true): array
    {
        $directory = preg_replace('/^[\/]*([^\/].*)$/', '/$1', $directory);
        $lines = ftp_rawlist($this->get_connection(), '-alR ' . $this->directory . $directory);
        if (false === $lines) {
            return ['keys' => [], 'dirs' => []];
        }
        $regex_dir = '/' . preg_quote($this->directory . $directory, '/') . '\/?(.+):$/u';
        $regex_item = '/^(?:([d\-\d])\S+)\s+\S+(?:(?:\s+\S+){5})?\s+(\S+)\s+(.+?)$/';
        $prev_line = null;
        $directories = [];
        $keys = ['keys' => [], 'dirs' => []];
        foreach ($lines as $line) {
            if ('' === $prev_line && preg_match($regex_dir, $line, $match)) {
                $directory = $match[1];
                unset($directories[$directory]);
                if ($only_keys) {
                    $keys = ['keys' => array_merge($keys['keys'], $keys['dirs']), 'dirs' => []];
                }
            } elseif (preg_match($regex_item, $line, $tokens)) {
                $name = $tokens[3];
                if ('.' === $name) {
                    continue;
                }
                if ('..' === $name) {
                    continue;
                }
                $path = ltrim($directory . '/' . $name, '/');
                if ('d' === $tokens[1] || '<dir>' === $tokens[2]) {
                    $keys['dirs'][] = $path;
                    $directories[$path] = true;
                } else {
                    $keys['keys'][] = $path;
                }
            }
            $prev_line = $line;
        }
        if ($only_keys) {
            $keys = ['keys' => array_merge($keys['keys'], $keys['dirs']), 'dirs' => []];
        }
        foreach (array_keys($directories) as $directory) {
            $keys = array_merge_recursive($keys, $this->fetch_keys($directory, $only_keys));
        }
        return $keys;
    }
    /**
     * Parses the given raw list.
     *
     *
     */
    private function parse_rawlist(array $rawlist): array
    {
        $parsed = [];
        foreach ($rawlist as $line) {
            $infos = preg_split("/[\\s]+/", $line, 9);
            if ($this->is_linux_listing($infos)) {
                $infos[7] = strrpos($infos[7], ':') != 2 ? $infos[7] . ' 00:00' : date('Y') . ' ' . $infos[7];
                if ('total' !== $infos[0]) {
                    $parsed[] = ['perms' => $infos[0], 'num' => $infos[1], 'size' => $infos[4], 'time' => strtotime($infos[5] . ' ' . $infos[6] . '. ' . $infos[7]), 'name' => $infos[8]];
                }
            } elseif (count($infos) >= 4) {
                $is_dir = '<dir>' === $infos[2];
                $parsed[] = ['perms' => $is_dir ? 'd' : '-', 'num' => '', 'size' => $is_dir ? '' : $infos[2], 'time' => strtotime($infos[0] . ' ' . $infos[1]), 'name' => $infos[3]];
            }
        }
        return $parsed;
    }
    /**
     * Computes the path for the given key.
     */
    private function compute_path(string $key): string
    {
        return rtrim($this->directory, '/') . '/' . $key;
    }
    /**
     * Indicates whether the adapter has an open ftp connection.
     */
    private function is_connected(): bool
    {
        if (class_exists(\FTP\Connection::class)) {
            return $this->connection instanceof \FTP\Connection;
        }
        return is_resource($this->connection);
    }
    /**
     * Returns an opened ftp connection resource. If the connection is not
     * already opened, it open it before.
     *
     * @return resource|\FTP\Connection The ftp connection
     */
    private function get_connection()
    {
        if (!$this->is_connected()) {
            $this->connect();
        }
        return $this->connection;
    }
    /**
     * Opens the adapter's ftp connection.
     *
     * @throws RuntimeException if could not connect
     */
    private function connect(): void
    {
        if ($this->ssl && !function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('This Server Has No SSL-FTP Available.');
        }
        // open ftp connection
        if (!$this->ssl) {
            $this->connection = ftp_connect($this->host, $this->port, $this->timeout);
        } else {
            $this->connection = ftp_ssl_connect($this->host, $this->port, $this->timeout);
        }
        if (!$this->connection) {
            throw new \RuntimeException(sprintf('Could not connect to \'%s\' (port: %s).', $this->host, $this->port));
        }
        if (defined('FTP_USEPASVADDRESS')) {
            ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
        }
        $username = $this->username ?: 'anonymous';
        $password = $this->password ?: '';
        // login ftp user
        if (!@ftp_login($this->connection, $username, $password)) {
            $this->close();
            throw new \RuntimeException(sprintf('Could not login as %s.', $username));
        }
        // switch to passive mode if needed
        if ($this->passive && !ftp_pasv($this->connection, true)) {
            $this->close();
            throw new \RuntimeException('Could not turn passive mode on.');
        }
        // enable utf8 mode if configured
        if ($this->utf8 == true) {
            ftp_raw($this->connection, 'OPTS UTF8 ON');
        }
        // ensure the adapter's directory exists
        if ('/' !== $this->directory) {
            try {
                $this->ensure_directory_exists($this->directory, $this->create);
            } catch (\RuntimeException $e) {
                $this->close();
                throw $e;
            }
            // change the current directory for the adapter's directory
            if (!ftp_chdir($this->connection, $this->directory)) {
                $this->close();
                throw new \RuntimeException(sprintf('Could not change current directory for the \'%s\' directory.', $this->directory));
            }
        }
    }
    /**
     * Closes the adapter's ftp connection.
     */
    public function close(): void
    {
        if ($this->is_connected()) {
            ftp_close($this->connection);
        }
    }
    private function is_linux_listing($info): bool
    {
        return count($info) >= 9;
    }
}