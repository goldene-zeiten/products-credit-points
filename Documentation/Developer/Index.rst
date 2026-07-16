:navigation-title: Developer

..  include:: /Includes.rst.txt
..  _developer:

=========
Developer
=========

EXT:products_credit_points is reached by EXT:products_core exclusively through the core's own
extension-point seams — the core carries no knowledge of credit points at all. This page documents
the three core contracts this extension implements or listens to, each with the extension's own real
code as a worked example. See the core extension's own documentation (Developer chapter) for the full
contract of each interface/event; this page only covers how this extension uses it.

..  contents:: Table of contents
    :local:

..  _developer-loyalty-provider:

Loyalty provider: LoyaltyProviderInterface
============================================

**Core location:** :php:`GoldeneZeiten\Products\Core\Loyalty\LoyaltyProviderInterface`

**Implementation:** :php:`GoldeneZeiten\Products\CreditPoints\Loyalty\CreditPointsLoyaltyProvider`,
registered under the identifier :code:`'credit_points'` (:php:`CreditPointsLoyaltyProvider::IDENTIFIER`).
Registration is automatic: the interface carries
:php:`#[AutoconfigureTag('products.loyalty_provider')]`, so no :file:`Services.yaml` entry is needed.

Every one of the interface's four lifecycle methods first checks
`products.creditPoints.enabled <configuration-settings>` and does nothing when it is off:

..  code-block:: php

    public function getBalance(LoyaltyContext $context): int
    {
        if (!$this->configuration($context)->isEnabled()) {
            return 0;
        }

        return $this->creditPointsService->getBalance($context->getFrontendUserUid());
    }

..  code-block:: php

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

The requested spend amount is never taken from a :php:`LoyaltyContext` property — it is read directly
off the request's parsed body (a plain ``spendPoints`` form field submitted with the checkout review
step), which is what keeps the core checkout controller unaware that a redeemable field exists at all:

..  code-block:: php

    private function requestedSpendPoints(LoyaltyContext $context): int
    {
        $body = $context->getRequest()->getParsedBody();

        return is_array($body) ? max(0, (int)($body['spendPoints'] ?? 0)) : 0;
    }

:php:`applyRedemption()` and :php:`award()` both run inside the order transaction (per the core
interface's contract), and both write one :php:`CreditPointsTransaction` ledger row through
:php:`GoldeneZeiten\Products\CreditPoints\Domain\Repository\CreditPointsTransactionRepository`.

Ledger and balance storage
----------------------------

Two tables back the programme:

*   :sql:`tx_products_domain_model_creditpointstransaction` — the append-only ledger. Every earn,
    redeem or manual adjustment is one row (:php:`CreditPointsTransactionType::EARN` /
    :code:`REDEEM` / :code:`ADJUSTMENT`), tied to a frontend user and, for earn/redeem, an order.
*   :sql:`tx_products_domain_model_creditpointsbalance` — a materialized, per-user balance kept in
    sync with the ledger, so a balance check does not have to sum the whole ledger on every checkout
    render.

:php:`GoldeneZeiten\Products\CreditPoints\Service\CreditPointsBalanceService::debitIfAffordable()` is
the guard against a balance being spent twice under concurrent requests: it issues a single
``UPDATE ... WHERE balance >= :points`` statement and reports failure via the affected-row count,
rather than a read-then-write that a second request could interleave with.

..  _developer-order-detail-panel:

Backend panel: OrderDetailPanelInterface
==========================================

**Core location:** :php:`GoldeneZeiten\Products\Core\Backend\OrderDetail\OrderDetailPanelInterface`

**Implementation:** :php:`GoldeneZeiten\Products\CreditPoints\Backend\OrderDetail\CreditPointsLedgerPanel`,
one panel among however many are registered for the :guilabel:`Discounts & rewards` section of the
backend order detail view. Like the loyalty provider, registration is automatic via
:php:`#[AutoconfigureTag('products.order_detail_panel')]` on the core interface; the core's
:php:`OrderDetailPanelRegistry` collects every tagged panel and drops the ones that render nothing for
a given order.

The interface has one method:

..  code-block:: php

    interface OrderDetailPanelInterface
    {
        /**
         * Rendered HTML for this order's panel, or null when this panel has nothing to show for the order.
         */
        public function renderForOrder(int $orderUid): ?string;
    }

The implementation reads the ledger directly (a :php:`ConnectionPool` query against
:sql:`tx_products_domain_model_creditpointstransaction` filtered by :sql:`order_uid`, not an Extbase
repository) and renders its own Fluid template when there is at least one row, returning ``null``
otherwise so the registry drops it entirely for orders with no credit-points activity:

..  code-block:: php

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

..  note::
    :php:`CreditPointsLedgerPanel` is registered ``public: true`` in this extension's
    :file:`Services.yaml`, specifically so a functional test can fetch it from the container directly —
    its only runtime consumer is the registry's tagged iterator, which would otherwise leave no
    fetchable service.

..  _developer-checkout-review-enrichment:

Checkout review enrichment: EnrichCheckoutReviewEvent
========================================================

**Core location:** :php:`GoldeneZeiten\Products\Core\Event\EnrichCheckoutReviewEvent`

**Listener:** :php:`GoldeneZeiten\Products\CreditPoints\EventListener\EnrichCheckoutReviewWithBalanceListener`

The core checkout controller dispatches this event while building the review step's template
variables, so add-ons can contribute extra variables without the controller knowing about them. This
extension listens for it to add the customer's current balance:

..  code-block:: php

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

The ``creditPointsBalance`` variable this adds is what the checkout review template's loyalty slot
partial reads to decide whether to show the redeem field at all — see
:ref:`Checkout redeem field <configuration-checkout-redeem-field>`. Without this extension installed,
the core dispatches the event to no listener, the variable is never set, and the review page's loyalty
slot (a no-op partial stub in the core) renders nothing.

..  note::
    The redeem field itself is not added through this event — it is added by this extension's own
    Fluid partial, which overrides the core's no-op ``LoyaltySlot`` stub by contributing a higher-priority
    partial root path (``plugin.tx_productscore.view.partialRootPaths.110``) in its site set's
    TypoScript. The event only supplies the balance the partial's condition and label depend on.

..  _developer-product-list-mode:

Product listing: ProductListModeProviderInterface
====================================================

**Core location:** :php:`GoldeneZeiten\Products\Core\Catalog\ProductListModeProviderInterface`

**Implementation:** :php:`GoldeneZeiten\Products\CreditPoints\Catalog\AffordableProductListModeProvider`,
registered under the mode :code:`'affordable'`. Like the other two interfaces above, registration is
automatic via the core interface's own :php:`#[AutoconfigureTag('products.product_list_mode')]`.

..  code-block:: php

    public function getMode(): string
    {
        return 'affordable';
    }

    public function findProducts(ProductListContext $context): array
    {
        if (!$this->creditPointsConfigurationFactory->create($context->getRequest())->isEnabled()) {
            return [];
        }
        $balance = $this->creditPointsBalanceService->getBalance($this->frontendUserResolver->getUid($context->getRequest()));
        if ($balance <= 0) {
            return [];
        }

        // ... loads products whose credit_points column is > 0 and <= $balance, cheapest first
    }

Because the per-product points value is a column this extension owns on the product table rather than
a property of the core's :php:`Product` model, :php:`findProducts()` resolves matching product uids
with a direct :php:`ConnectionPool` query (ordered by :sql:`credit_points` ascending) and then loads
each one through the core's own :php:`ProductRepository` — never an Extbase query over a property the
core does not have.
