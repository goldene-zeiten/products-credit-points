<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GoldeneZeiten\Products\Core\Service\Database\IsolatedWrite;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Atomic per-user balance guarded against concurrent over-spend.
 *
 * @see \GoldeneZeiten\Products\Core\Service\Order\StockService::decrementForItem()
 */
final class CreditPointsBalanceService
{
    private const BALANCE_TABLE = 'tx_products_domain_model_creditpointsbalance';
    private const LEDGER_TABLE = 'tx_products_domain_model_creditpointstransaction';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly IsolatedWrite $isolatedWrite
    ) {}

    public function getBalance(int $frontendUser): int
    {
        if ($frontendUser === 0) {
            return 0;
        }
        $this->ensureRowExists($frontendUser);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::BALANCE_TABLE);
        $balance = $queryBuilder
            ->select('balance')
            ->from(self::BALANCE_TABLE)
            ->where($queryBuilder->expr()->eq('frontend_user', $queryBuilder->createNamedParameter($frontendUser, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return $balance === false ? 0 : (int)$balance;
    }

    public function credit(int $frontendUser, int $points): void
    {
        $this->ensureRowExists($frontendUser);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::BALANCE_TABLE);
        $queryBuilder->update(self::BALANCE_TABLE)
            ->set('balance', $queryBuilder->quoteIdentifier('balance') . ' + ' . $queryBuilder->createNamedParameter($points, Connection::PARAM_INT), false)
            ->where($queryBuilder->expr()->eq('frontend_user', $queryBuilder->createNamedParameter($frontendUser, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Atomic UPDATE...WHERE guard against concurrent over-spend.
     *
     * @return bool false if the balance could not afford this many points
     */
    public function debitIfAffordable(int $frontendUser, int $points): bool
    {
        $this->ensureRowExists($frontendUser);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::BALANCE_TABLE);
        $affectedRows = $queryBuilder->update(self::BALANCE_TABLE)
            ->set('balance', $queryBuilder->quoteIdentifier('balance') . ' - ' . $queryBuilder->createNamedParameter($points, Connection::PARAM_INT), false)
            ->where(
                $queryBuilder->expr()->eq('frontend_user', $queryBuilder->createNamedParameter($frontendUser, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('balance', $queryBuilder->createNamedParameter($points, Connection::PARAM_INT))
            )
            ->executeStatement();

        return $affectedRows > 0;
    }

    /**
     * Idempotent: concurrent double-insert is safe - the loser's constraint violation is ignored. The insert
     * runs under a savepoint, as it is reached inside the order placement's transaction, which the failed
     * statement would otherwise abort on PostgreSQL.
     */
    private function ensureRowExists(int $frontendUser): void
    {
        $selectQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::BALANCE_TABLE);
        $exists = $selectQueryBuilder
            ->count('frontend_user')
            ->from(self::BALANCE_TABLE)
            ->where($selectQueryBuilder->expr()->eq('frontend_user', $selectQueryBuilder->createNamedParameter($frontendUser, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        if ((int)$exists > 0) {
            return;
        }

        $ledgerQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::LEDGER_TABLE);
        $ledgerSum = $ledgerQueryBuilder
            ->selectLiteral('SUM(' . $ledgerQueryBuilder->quoteIdentifier('points') . ') AS balance')
            ->from(self::LEDGER_TABLE)
            ->where($ledgerQueryBuilder->expr()->eq('frontend_user', $ledgerQueryBuilder->createNamedParameter($frontendUser, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        $failure = $this->isolatedWrite->run(
            self::BALANCE_TABLE,
            fn() => $this->connectionPool->getConnectionForTable(self::BALANCE_TABLE)->insert(self::BALANCE_TABLE, [
                'frontend_user' => $frontendUser,
                'balance' => (int)($ledgerSum ?? 0),
            ])
        );
        // A unique violation means another concurrent request already created (and initialized) the row.
        if ($failure !== null && !$failure instanceof UniqueConstraintViolationException) {
            throw $failure;
        }
    }
}
