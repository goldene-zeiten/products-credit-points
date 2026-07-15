<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Catalog;

use GoldeneZeiten\Products\Core\Catalog\ProductListModeProviderInterface;
use GoldeneZeiten\Products\Core\Domain\Dto\Catalog\ProductListContext;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Service\FrontendUserResolver;
use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfigurationFactory;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsBalanceService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * "Products you can afford with your points" - the credit-points programme's own listing, registered as a
 * product list mode so it can be placed like any other listing.
 *
 * The per-product point value is a column this extension owns on the product table, not a property of the
 * core Product model, so this reads it with a direct query and then loads the matching products through the
 * core repository rather than through an Extbase query over a property the core does not have.
 */
final class AffordableProductListModeProvider implements ProductListModeProviderInterface
{
    private const PRODUCT_TABLE = 'tx_products_domain_model_product';

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly CreditPointsBalanceService $creditPointsBalanceService,
        private readonly CreditPointsConfigurationFactory $creditPointsConfigurationFactory,
        private readonly FrontendUserResolver $frontendUserResolver,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getMode(): string
    {
        return 'affordable';
    }

    public function getLabel(): string
    {
        return (string)LocalizationUtility::translate(
            'tt_content.tx_products_list_mode.affordable',
            'ProductsCreditPoints',
        );
    }

    /**
     * @return Product[]
     */
    public function findProducts(ProductListContext $context): array
    {
        if (!$this->creditPointsConfigurationFactory->create($context->getRequest())->isEnabled()) {
            return [];
        }
        $balance = $this->creditPointsBalanceService->getBalance($this->frontendUserResolver->getUid($context->getRequest()));
        if ($balance <= 0) {
            return [];
        }

        $products = [];
        foreach ($this->affordableProductUids($balance) as $uid) {
            $product = $this->productRepository->findByUid($uid);
            if ($product instanceof Product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return int[] the uids of products costing at most the balance, cheapest first
     */
    private function affordableProductUids(int $balance): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PRODUCT_TABLE);

        return array_map('intval', $queryBuilder
            ->select('uid')
            ->from(self::PRODUCT_TABLE)
            ->where(
                $queryBuilder->expr()->gt('credit_points', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('credit_points', $queryBuilder->createNamedParameter($balance, Connection::PARAM_INT)),
            )
            ->orderBy('credit_points', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn());
    }
}
