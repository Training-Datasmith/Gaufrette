<?php

declare (strict_types=1);
namespace Gaufrette\Adapter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Gaufrette\Adapter;
use Gaufrette\Util;
/**
 * Doctrine DBAL adapter.
 *
 * @author Markus Bachmann <markus.bachmann@bachi.biz>
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Doctrine_Dbal implements Adapter, Checksum_Calculator, List_Keys_Aware
{
    protected \Doctrine\DBAL\Connection $connection;
    protected $table;
    protected array $columns = ['key' => 'key', 'content' => 'content', 'mtime' => 'mtime', 'checksum' => 'checksum'];
    /**
     * @param Connection $connection The DBAL connection
     * @param string     $table      The files table
     * @param array      $columns    The column names
     */
    public function __construct(Connection $connection, $table, array $columns = [])
    {
        if (!class_exists(Connection::class)) {
            throw new \LogicException('You need to install package "doctrine/dbal" to use this adapter');
        }
        $this->connection = $connection;
        $this->table = $table;
        $this->columns = array_replace($this->columns, $columns);
    }
    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        $stmt = $this->connection->execute_query(sprintf('SELECT %s FROM %s', $this->get_quoted_column('key'), $this->get_quoted_table()));
        if (class_exists(Result::class)) {
            // dbal 3.x
            return $stmt->fetch_first_column();
        }
        // BC layer for dbal 2.x
        return $stmt->fetch_all(\PDO::FETCH_COLUMN);
    }
    /**
     * {@inheritdoc}
     */
    public function rename($source_key, $target_key): bool
    {
        return (bool) $this->connection->update($this->table, [$this->get_quoted_column('key') => $target_key], [$this->get_quoted_column('key') => $source_key]);
    }
    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        return $this->get_column_value($key, 'mtime');
    }
    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        return $this->get_column_value($key, 'checksum');
    }
    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        $method = 'fetchOne';
        // dbal 3.x
        if (!method_exists(Connection::class, $method)) {
            $method = 'fetchColumn';
            // BC layer for dbal 2.x
        }
        return (bool) $this->connection->{$method}(sprintf('SELECT COUNT(%s) FROM %s WHERE %s = :key', $this->get_quoted_column('key'), $this->get_quoted_table(), $this->get_quoted_column('key')), ['key' => $key]);
    }
    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        return $this->get_column_value($key, 'content');
    }
    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        return (bool) $this->connection->delete($this->table, [$this->get_quoted_column('key') => $key]);
    }
    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $values = [$this->get_quoted_column('content') => $content, $this->get_quoted_column('mtime') => time(), $this->get_quoted_column('checksum') => Util\Checksum::from_content($content)];
        if ($this->exists($key)) {
            $this->connection->update($this->table, $values, [$this->get_quoted_column('key') => $key]);
        } else {
            $values[$this->get_quoted_column('key')] = $key;
            $this->connection->insert($this->table, $values);
        }
        return Util\Size::from_content($content);
    }
    /**
     * {@inheritdoc}
     */
    public function is_directory($key): bool
    {
        return false;
    }
    private function get_column_value($key, string $column)
    {
        $method = 'fetchOne';
        // dbal 3.x
        if (!method_exists(Connection::class, $method)) {
            $method = 'fetchColumn';
            // BC layer for dbal 2.x
        }
        return $this->connection->{$method}(sprintf('SELECT %s FROM %s WHERE %s = :key', $this->get_quoted_column($column), $this->get_quoted_table(), $this->get_quoted_column('key')), ['key' => $key]);
    }
    /**
     * {@inheritdoc}
     */
    public function list_keys($prefix = ''): array
    {
        $prefix = trim($prefix);
        $method = 'fetchAllAssociative';
        // dbal 3.x
        if (!method_exists(Connection::class, 'fetchAllAssociative')) {
            $method = 'fetchAll';
            // BC layer for dbal 2.x
        }
        $keys = $this->connection->{$method}(sprintf('SELECT %s AS _key FROM %s WHERE %s LIKE :pattern', $this->get_quoted_column('key'), $this->get_quoted_table(), $this->get_quoted_column('key')), ['pattern' => sprintf('%s%%', $prefix)]);
        return ['dirs' => [], 'keys' => array_map(fn(array $value) => $value['_key'], $keys)];
    }
    private function get_quoted_table()
    {
        return $this->connection->quote_identifier($this->table);
    }
    private function get_quoted_column($column)
    {
        return $this->connection->quote_identifier($this->columns[$column]);
    }
}