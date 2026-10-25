<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Telescope;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Inverge\Nexus\Telescope\Telescope;

/**
 * Doctrine DBAL middleware (DBAL 3.2+ / 4): times every query and records it in
 * the current journey. `exec()` is deliberately not wrapped — its return type
 * differs between DBAL 3 (`int`) and 4 (`int|string`), and one class cannot
 * satisfy both; parameterised statements (the ORM's) go through `prepare()`. Tagged `doctrine.middleware`, so DoctrineBundle applies
 * it to every connection. Prepared-statement bindings are not captured (their
 * signatures differ between DBAL versions); the SQL shape is what N+1 and
 * query-family analysis use.
 */
final class DoctrineMiddleware implements Middleware
{
    public function __construct(private readonly Telescope $telescope)
    {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        $telescope = $this->telescope;

        return new class ($driver, $telescope) extends AbstractDriverMiddleware {
            public function __construct(DriverInterface $driver, private readonly Telescope $telescope)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): ConnectionInterface
            {
                $connection = parent::connect($params);
                $name = (string) ($params['dbname'] ?? $params['driver'] ?? 'default');

                return new class ($connection, $this->telescope, $name) extends AbstractConnectionMiddleware {
                    public function __construct(ConnectionInterface $c, private readonly Telescope $telescope, private readonly string $name)
                    {
                        parent::__construct($c);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $stmt = parent::prepare($sql);
                        $telescope = $this->telescope;
                        $name = $this->name;

                        return new class ($stmt, $telescope, $sql, $name) extends AbstractStatementMiddleware {
                            public function __construct(Statement $s, private readonly Telescope $telescope, private readonly string $sql, private readonly string $name)
                            {
                                parent::__construct($s);
                            }

                            public function execute($params = null): Result
                            {
                                $t = microtime(true);
                                try {
                                    return $params === null ? parent::execute() : parent::execute($params);
                                } finally {
                                    $this->telescope->query($this->sql, \is_array($params) ? $params : [], (microtime(true) - $t) * 1000, $this->name);
                                }
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $t = microtime(true);
                        try {
                            return parent::query($sql);
                        } finally {
                            $this->telescope->query($sql, [], (microtime(true) - $t) * 1000, $this->name);
                        }
                    }
                };
            }
        };
    }
}
