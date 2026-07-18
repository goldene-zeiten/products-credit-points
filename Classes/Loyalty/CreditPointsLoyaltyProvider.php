<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Loyalty;

use GoldeneZeiten\Products\Core\Domain\Dto\Loyalty\LoyaltyContext;
use GoldeneZeiten\Products\Core\Domain\Enum\AdjustmentType;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Domain\ValueObject\CheckoutAdjustment;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Core\Loyalty\LoyaltyProviderInterface;
use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfiguration;
use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfigurationFactory;
use GoldeneZeiten\Products\CreditPoints\Domain\Dto\CreditPointsRedemption;
use GoldeneZeiten\Products\CreditPoints\Domain\Enum\CreditPointsTransactionType;
use GoldeneZeiten\Products\CreditPoints\Domain\Model\CreditPointsTransaction;
use GoldeneZeiten\Products\CreditPoints\Domain\Repository\CreditPointsTransactionRepository;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsBalanceService;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsService;
use GoldeneZeiten\Products\CreditPoints\Service\Exception\InsufficientCreditPointsException;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The credit-points programme, seen through the loyalty contract: it ships with the extension so a shop
 * has loyalty out of the box, and the checkout reaches it only as a loyalty provider. The atomic debit
 * guards against a balance being spent twice.
 */
final class CreditPointsLoyaltyProvider implements LoyaltyProviderInterface
{
    /**
     * The identifier this programme tags its loyalty adjustments with. It is owned by the extension, not
     * the core, so the core stays unaware that a credit-points programme exists at all.
     */
    public const IDENTIFIER = 'credit_points';

    public function __construct(
        private readonly CreditPointsService $creditPointsService,
        private readonly CreditPointsBalanceService $creditPointsBalanceService,
        private readonly CreditPointsTransactionRepository $creditPointsTransactionRepository,
        private readonly CreditPointsConfigurationFactory $creditPointsConfigurationFactory,
        private readonly PersistenceManagerInterface $persistenceManager
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getBalance(LoyaltyContext $context): int
    {
        if (!$this->configuration($context)->isEnabled()) {
            return 0;
        }

        return $this->creditPointsService->getBalance($context->getFrontendUserUid());
    }

    public function assertRedeemable(LoyaltyContext $context): void
    {
        if (!$this->configuration($context)->isEnabled() || $this->requestedSpendPoints($context) <= 0) {
            return;
        }
        $this->creditPointsService->assertSpendable($context->getFrontendUserUid(), $this->requestedSpendPoints($context));
    }

    public function quoteRedemption(LoyaltyContext $context): ?CheckoutAdjustment
    {
        if (!$this->configuration($context)->isEnabled()) {
            return null;
        }
        $redemption = $this->redeem($context);
        if ($redemption->getDiscountAmount()->getCents() === 0) {
            return null;
        }

        return new CheckoutAdjustment(
            AdjustmentType::LOYALTY,
            self::IDENTIFIER,
            '',
            Money::fromCents(-$redemption->getDiscountAmount()->getCents()),
            0.0,
            ['points' => (string)$redemption->getPoints()]
        );
    }

    public function applyRedemption(Order $order, LoyaltyContext $context): void
    {
        if (!$this->configuration($context)->isEnabled()) {
            return;
        }
        $points = $this->redeem($context)->getPoints();
        if ($points <= 0) {
            return;
        }
        if (!$this->creditPointsBalanceService->debitIfAffordable($context->getFrontendUserUid(), $points)) {
            throw new InsufficientCreditPointsException(
                sprintf('Requested %d credit points but the balance could not afford it at redemption time.', $points),
                1783430100
            );
        }
        $this->creditPointsTransactionRepository->add($this->buildTransaction($order, $context->getFrontendUserUid(), -$points, CreditPointsTransactionType::REDEEM));
        $this->persistenceManager->persistAll();
    }

    public function award(Order $order, LoyaltyContext $context): void
    {
        $configuration = $this->configuration($context);
        if (!$configuration->isEnabled() || $context->getFrontendUserUid() === 0) {
            return;
        }
        $earned = $this->creditPointsService->calculateEarnedPoints($context->getBasketViewModel(), $configuration);
        if ($earned <= 0) {
            return;
        }
        $this->creditPointsBalanceService->credit($context->getFrontendUserUid(), $earned);
        $this->creditPointsTransactionRepository->add($this->buildTransaction($order, $context->getFrontendUserUid(), $earned, CreditPointsTransactionType::EARN));
        $this->persistenceManager->persistAll();
    }

    private function redeem(LoyaltyContext $context): CreditPointsRedemption
    {
        return $this->creditPointsService->redeem(
            $context->getFrontendUserUid(),
            $this->requestedSpendPoints($context),
            $context->getRemainingGoodsTotal(),
            $this->configuration($context)
        );
    }

    /**
     * The redeem amount is submitted with the checkout as a plain "spendPoints" field. The provider reads
     * it from the request itself, so the core checkout stays unaware of any loyalty programme.
     */
    private function requestedSpendPoints(LoyaltyContext $context): int
    {
        $body = $context->getRequest()->getParsedBody();

        return is_array($body) ? max(0, (int)($body['spendPoints'] ?? 0)) : 0;
    }

    private function configuration(LoyaltyContext $context): CreditPointsConfiguration
    {
        return $this->creditPointsConfigurationFactory->create($context->getRequest());
    }

    private function buildTransaction(Order $order, int $frontendUser, int $points, CreditPointsTransactionType $type): CreditPointsTransaction
    {
        $transaction = new CreditPointsTransaction();
        $transaction->setFrontendUser($frontendUser);
        $transaction->setOrderUid($order->getUid() ?? 0);
        $transaction->setPoints($points);
        $transaction->setType($type);
        $transaction->setCreated(new \DateTime());

        return $transaction;
    }
}
