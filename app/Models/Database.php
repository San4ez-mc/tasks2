<?php
/**
 * Клас для роботи з БД
 */

namespace App\Models;

use PDO;
use PDOException;

class Database
{
    private $connection;
    private $stmt;

    public function __construct()
    {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ':' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $this->connection = new PDO($dsn, DB_USER, DB_PASSWORD);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Помилка підключення БД: ' . $e->getMessage());
        }
    }

    /**
     * Підготувати запит
     */
    public function query($sql)
    {
        $this->stmt = $this->connection->prepare($sql);
        return $this;
    }

    /**
     * Прив'язати параметр
     */
    public function bind($param, $value, $type = null)
    {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
        return $this;
    }

    /**
     * Виконати запит
     */
    public function execute()
    {
        return $this->stmt->execute();
    }

    /**
     * Отримати один рядок
     */
    public function fetch($fetchMode = PDO::FETCH_ASSOC)
    {
        $this->execute();
        return $this->stmt->fetch($fetchMode);
    }

    /**
     * Отримати всі рядки
     */
    public function fetchAll($fetchMode = PDO::FETCH_ASSOC)
    {
        $this->execute();
        return $this->stmt->fetchAll($fetchMode);
    }

    /**
     * Отримати кількість рядків
     */
    public function rowCount()
    {
        return $this->stmt->rowCount();
    }

    /**
     * Вставити новий запис
     */
    public function insert($table, $data)
    {
        $keys = array_keys($data);
        $placeholders = array_map(function ($key) {
            return ':' . $key; }, $keys);

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $this->query($sql);
        foreach ($data as $key => $value) {
            $this->bind(':' . $key, $value);
        }

        return $this->execute();
    }

    /**
     * Оновити запис
     */
    public function update($table, $id, $data)
    {
        $set = array_map(function ($key) {
            return $key . ' = :' . $key; }, array_keys($data));
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = :id';

        $this->query($sql);
        $data['id'] = $id;
        foreach ($data as $key => $value) {
            $this->bind(':' . $key, $value);
        }

        return $this->execute();
    }

    /**
     * Видалити запис
     */
    public function delete($table, $id)
    {
        $sql = 'DELETE FROM ' . $table . ' WHERE id = :id';
        $this->query($sql)->bind(':id', $id);
        return $this->execute();
    }

    /**
     * Отримати останній ID
     */
    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Почати транзакцію
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Зберегти транзакцію
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Відкотити транзакцію
     */
    public function rollback()
    {
        return $this->connection->rollback();
    }
}
