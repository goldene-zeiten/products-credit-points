<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Backend\OrderDetail;

use GoldeneZeiten\Products\Core\Backend\OrderDetail\OrderDetailPanelInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The credit-points ledger of an order, shown as an order-detail panel in the backend order module. The
 * core reaches it only through the tagged order-detail-panel contract, so it stays unaware of the ledger.
 *
 * Registered public in Services.yaml so a functional test can fetch it directly: its only other consumer
 * is the panel registry's tagged iterator, which inlines it and leaves no fetchable service otherwise.
 */
final readonly class CreditPointsLedgerPanel implements OrderDetailPanelInterface
{
    private const LEDGER_TABLE = 'tx_products_domain_model_creditpointstransaction';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ViewFactoryInterface $viewFactory,
    ) {}

    public function renderForOrder(int $orderUid): ?string
    {
        $rows = $this->fetchLedger($orderUid);
        if ($rows === []) {
            return null;
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:products_credit_points/Resources/Private/Backend/Templates/'],
            partialRootPaths: ['EXT:products_credit_points/Resources/Private/Backend/Partials/'],
        ));
        $view->assign('ledger', $rows);

        return $view->render('OrderDetail/CreditPointsLedger');
    }

    /**
     * @return array<int, array{type: string, points: int, created: ?string}>
     */
    private function fetchLedger(int $orderUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::LEDGER_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $queryBuilder
            ->select('*')
            ->from(self::LEDGER_TABLE)
            ->where(
                $queryBuilder->expr()->eq('order_uid', $queryBuilder->createNamedParameter($orderUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'type' => (string)$row['type'],
                'points' => (int)$row['points'],
                'created' => (int)($row['created'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$row['created']) : null,
            ],
            $rows,
        );
    }
}
