<?php

/** Internal immutable identity of a connection-owned database lock; never raw SQL. */
final class Kiwi_Database_Lock_Guard
{
    private $name;
    private $connection_id;

    public function __construct(string $name, int $connection_id)
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1 || $connection_id < 1) {
            throw new InvalidArgumentException('Invalid database lock identity.');
        }
        $this->name = $name;
        $this->connection_id = $connection_id;
    }

    public function name(): string { return $this->name; }
    public function connection_id(): int { return $this->connection_id; }
}
