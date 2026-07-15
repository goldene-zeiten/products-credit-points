<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\EventListener;

use GoldeneZeiten\Products\Core\Domain\Dto\Loyalty\LoyaltyContext;
use GoldeneZeiten\Products\Core\Event\EnrichCheckoutReviewEvent;
use GoldeneZeiten\Products\Core\Service\Checkout\CheckoutService;
use GoldeneZeiten\Products\Core\Service\FrontendUserResolver;
use GoldeneZeiten\Products\CreditPoints\Loyalty\CreditPointsLoyaltyProvider;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Adds the customer's credit-points balance to the checkout review view, so the loyalty slot partial this
 * extension overrides into that view can show the redeem field and its cap. With this extension absent the
 * core dispatches the event to no one and the review page carries no balance, hiding the field entirely.
 */
final readonly class EnrichCheckoutReviewWithBalanceListener
{
    public function __construct(
        private CheckoutService $checkoutService,
        private FrontendUserResolver $frontendUserResolver,
        private CreditPointsLoyaltyProvider $creditPointsLoyaltyProvider,
    ) {}

    #[AsEventListener]
    public function __invoke(EnrichCheckoutReviewEvent $event): void
    {
        $request = $event->getRequest();
        $basket = $this->checkoutService->getBasketViewModel($request);
        $context = new LoyaltyContext(
            $request,
            $basket,
            $basket->getTotalGross(),
            $this->frontendUserResolver->getUid($request),
        );

        $event->addVariable('creditPointsBalance', $this->creditPointsLoyaltyProvider->getBalance($context));
    }
}
