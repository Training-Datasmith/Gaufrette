<?php

declare (strict_types=1);
namespace Gaufrette\Stream;

use Gaufrette\Filesystem;
use Gaufrette\Stream;
use Gaufrette\Stream_Mode;
use Gaufrette\Util;
class In_Memory_Buffer implements Stream
{
    private \Gaufrette\Filesystem $filesystem;
    private $key;
    private ?\Gaufrette\Stream_Mode $mode = null;
    private $content;
    private $num_bytes;
    private $position;
    private ?bool $synchronized = null;
    /**
     * @param Filesystem $filesystem The filesystem managing the file to stream
     * @param string     $key        The file key
     */
    public function __construct(Filesystem $filesystem, $key)
    {
        $this->filesystem = $filesystem;
        $this->key = $key;
    }
    /**
     * {@inheritdoc}
     */
    public function open(Stream_Mode $mode): bool
    {
        $this->mode = $mode;
        $exists = $this->filesystem->has($this->key);
        if ($exists && !$mode->allows_existing_file_opening() || !$exists && !$mode->allows_new_file_opening()) {
            return false;
        }
        if ($mode->implies_existing_content_deletion()) {
            $this->content = $this->write_content('');
        } elseif (!$exists && $mode->allows_new_file_opening()) {
            $this->content = $this->write_content('');
        } else {
            $this->content = $this->filesystem->read($this->key);
        }
        $this->num_bytes = Util\Size::from_content($this->content);
        $this->position = $mode->implies_positioning_cursor_at_the_end() ? $this->num_bytes : 0;
        $this->synchronized = true;
        return true;
    }
    public function read($count): string
    {
        if (false === $this->mode->allows_read()) {
            throw new \LogicException('The stream does not allow read.');
        }
        $chunk = substr($this->content, $this->position, $count);
        $this->position += Util\Size::from_content($chunk);
        return $chunk;
    }
    public function write($data)
    {
        if (false === $this->mode->allows_write()) {
            throw new \LogicException('The stream does not allow write.');
        }
        $num_written_bytes = Util\Size::from_content($data);
        $new_position = $this->position + $num_written_bytes;
        $new_num_bytes = $new_position > $this->num_bytes ? $new_position : $this->num_bytes;
        if ($this->eof()) {
            $this->num_bytes += $num_written_bytes;
            if ($this->has_new_content_at_further_position()) {
                $data = str_pad($data, $this->position + strlen($data), ' ', STR_PAD_LEFT);
            }
            $this->content .= $data;
        } else {
            $before = substr($this->content, 0, $this->position);
            $after = $new_num_bytes > $new_position ? substr($this->content, $new_position) : '';
            $this->content = $before . $data . $after;
        }
        $this->position = $new_position;
        $this->num_bytes = $new_num_bytes;
        $this->synchronized = false;
        return $num_written_bytes;
    }
    public function close(): void
    {
        if (!$this->synchronized) {
            $this->flush();
        }
    }
    public function seek($offset, $whence = SEEK_SET): bool
    {
        switch ($whence) {
            case SEEK_SET:
                $this->position = $offset;
                break;
            case SEEK_CUR:
                $this->position += $offset;
                break;
            case SEEK_END:
                $this->position = $this->num_bytes + $offset;
                break;
            default:
                return false;
        }
        return true;
    }
    public function tell()
    {
        return $this->position;
    }
    public function flush(): bool
    {
        if ($this->synchronized) {
            return true;
        }
        try {
            $this->write_content($this->content);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }
    public function eof(): bool
    {
        return $this->position >= $this->num_bytes;
    }
    /**
     * {@inheritdoc}
     */
    public function stat()
    {
        if ($this->filesystem->has($this->key)) {
            $is_directory = $this->filesystem->is_directory($this->key);
            $time = $this->filesystem->mtime($this->key);
            $stats = ['dev' => 1, 'ino' => 0, 'mode' => $is_directory ? 16893 : 33204, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => $is_directory ? 0 : Util\Size::from_content($this->content), 'atime' => $time, 'mtime' => $time, 'ctime' => $time, 'blksize' => -1, 'blocks' => -1];
            return array_merge(array_values($stats), $stats);
        }
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function cast($cast_ast): bool
    {
        return false;
    }
    /**
     * {@inheritdoc}
     */
    public function unlink()
    {
        if ($this->mode && $this->mode->implies_existing_content_deletion()) {
            return $this->filesystem->delete($this->key);
        }
        return false;
    }
    protected function has_new_content_at_further_position(): bool
    {
        return $this->position > 0 && !$this->content;
    }
    /**
     * @param string $content   Empty string by default
     * @param bool   $overwrite Overwrite by default
     *
     * @return string
     */
    protected function write_content($content = '', $overwrite = true)
    {
        $this->filesystem->write($this->key, $content, $overwrite);
        return $content;
    }
}