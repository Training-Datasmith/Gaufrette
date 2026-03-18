<?php

namespace Gaufrette;

/**
 * Represents a stream mode.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class StreamMode
{
    private $mode;
    private $base;
    private bool $plus;
    private string $flag;

    /**
     * @param string $mode A stream mode as for the use of fopen()
     */
    public function __construct($mode)
    {
        $this->mode = $mode;

        $mode = substr($mode, 0, 3);
        $rest = substr($mode, 1);

        $this->base = substr($mode, 0, 1);
        $this->plus = false !== strpos($rest, '+');
        $this->flag = trim($rest, '+');
    }

    /**
     * Returns the underlying mode.
     *
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Indicates whether the mode allows to read.
     *
     * @return bool
     */
    public function allowsRead()
    {
        if ($this->plus) {
            return true;
        }

        return 'r' === $this->base;
    }

    /**
     * Indicates whether the mode allows to write.
     *
     * @return bool
     */
    public function allowsWrite()
    {
        if ($this->plus) {
            return true;
        }

        return 'r' !== $this->base;
    }

    /**
     * Indicates whether the mode allows to open an existing file.
     */
    public function allowsExistingFileOpening(): bool
    {
        return 'x' !== $this->base;
    }

    /**
     * Indicates whether the mode allows to create a new file.
     */
    public function allowsNewFileOpening(): bool
    {
        return 'r' !== $this->base;
    }

    /**
     * Indicates whether the mode implies to delete the existing content of the
     * file when it already exists.
     */
    public function impliesExistingContentDeletion(): bool
    {
        return 'w' === $this->base;
    }

    /**
     * Indicates whether the mode implies positioning the cursor at the
     * beginning of the file.
     */
    public function impliesPositioningCursorAtTheBeginning(): bool
    {
        return 'a' !== $this->base;
    }

    /**
     * Indicates whether the mode implies positioning the cursor at the end of
     * the file.
     */
    public function impliesPositioningCursorAtTheEnd(): bool
    {
        return 'a' === $this->base;
    }

    /**
     * Indicates whether the stream is in binary mode.
     */
    public function isBinary(): bool
    {
        return 'b' === $this->flag;
    }

    /**
     * Indicates whether the stream is in text mode.
     */
    public function isText(): bool
    {
        return false === $this->isBinary();
    }
}
