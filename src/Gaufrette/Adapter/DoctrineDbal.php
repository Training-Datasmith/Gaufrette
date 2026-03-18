<?php

declare(strict_types=1);

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
class DoctrineDbal implements Adapter, ChecksumCalculator, ListKeysAware
{
    protected \Doctrine\DBAL\Connection $connection;
    protected $table;
    protected array $columns = [
        'key' => 'key',
        'content' => 'content',
        'mtime' => 'mtime',
        'checksum' => 'checksum',
    ];

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
        $stmt = $this->connection->executeQuery(sprintf(
            'SELECT %s FROM %s',
            $this->getQuotedColumn('key'),
            $this->getQuotedTable()
        ));

        if (class_exists(Result::class)) {
            // dbal 3.x
            return $stmt->fetchFirstColumn();
        }

        // BC layer for dbal 2.x
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey): bool
    {
        return (bool) $this->connection->update(
            $this->table,
            [$this->getQuotedColumn('key') => $targetKey],
            [$this->getQuotedColumn('key') => $sourceKey]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        return $this->getColumnValue($key, 'mtime');
    }

    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        return $this->getColumnValue($key, 'checksum');
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key): bool
    {
        $method = 'fetchOne'; // dbal 3.x
        if (!method_exists(Connection::class, $method)) {
            $method = 'fetchColumn'; // BC layer for dbal 2.x
        }

        return (bool) $this->connection->$method(
            sprintf(
                'SELECT COUNT(%s) FROM %s WHERE %s = :key',
                $this->getQuotedColumn('key'),
                $this->getQuotedTable(),
                $this->getQuotedColumn('key')
            ),
            ['key' => $key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        return $this->getColumnValue($key, 'content');
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        return (bool) $this->connection->delete(
            $this->table,
            [$this->getQuotedColumn('key') => $key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $values = [
            $this->getQuotedColumn('content') => $content,
            $this->getQuotedColumn('mtime') => time(),
            $this->getQuotedColumn('checksum') => Util\Checksum::fromContent($content),
        ];

        if ($this->exists($key)) {
            $this->connection->update(
                $this->table,
                $values,
                [$this->getQuotedColumn('key') => $key]
            );
        } else {
            $values[$this->getQuotedColumn('key')] = $key;
            $this->connection->insert($this->table, $values);
        }

        return Util\Size::fromContent($content);
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key): bool
    {
        return false;
    }

    private function getColumnValue($key, string $column)
    {
        $method = 'fetchOne'; // dbal 3.x
        if (!method_exists(Connection::class, $method)) {
            $method = 'fetchColumn'; // BC layer for dbal 2.x
        }

        return $this->connection->$method(
            sprintf(
                'SELECT %s FROM %s WHERE %s = :key',
                $this->getQuotedColumn($column),
                $this->getQuotedTable(),
                $this->getQuotedColumn('key')
            ),
            ['key' => $key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = ''): array
    {
        $prefix = trim($prefix);

        $method = 'fetchAllAssociative'; // dbal 3.x
        if (!method_exists(Connection::class, 'fetchAllAssociative')) {
            $method = 'fetchAll'; // BC layer for dbal 2.x
        }

        $keys = $this->connection->$method(
            sprintf(
                'SELECT %s AS _key FROM %s WHERE %s LIKE :pattern',
                $this->getQuotedColumn('key'),
                $this->getQuotedTable(),
                $this->getQuotedColumn('key')
            ),
            ['pattern' => sprintf('%s%%', $prefix)]
        );

        return [
            'dirs' => [],
            'keys' => array_map(
                fn (array $value) => $value['_key'],
                $keys
            ),
        ];
    }

    private function getQuotedTable()
    {
        return $this->connection->quoteIdentifier($this->table);
    }

    private function getQuotedColumn($column)
    {
        return $this->connection->quoteIdentifier($this->columns[$column]);
    }
}
