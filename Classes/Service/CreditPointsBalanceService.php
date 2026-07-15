<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Service;

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
        private readonly ConnectionPool $connectionPool
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
     * Idempotent: concurrent double-insert is safe (loser's constraint violation ignored).
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

        try {
            $this->connectionPool->getConnectionForTable(self::BALANCE_TABLE)->insert(self::BALANCE_TABLE, [
                'frontend_user' => $frontendUser,
                'balance' => (int)($ledgerSum ?? 0),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Another concurrent request already created (and correctly initialized) the row.
        }
    }
}
