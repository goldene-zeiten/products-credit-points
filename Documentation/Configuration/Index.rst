:navigation-title: Configuration

..  include:: /Includes.rst.txt
..  _configuration:

=============
Configuration
=============

..  contents:: Table of contents
    :local:

..  _configuration-site-set:

Site set
========

Activate the :guilabel:`Products Credit Points` site set (``goldene-zeiten/products-credit-points``) on
every site that should offer the loyalty programme, then adjust its settings under
:guilabel:`Site Management > Sites > Edit settings` (grouped under the :guilabel:`Credit Points`
category).

..  _configuration-settings:

Settings
========

..  confval-menu::
    :name: settings-overview
    :display: table
    :type:
    :Default:

    ..  confval:: products.creditPoints.enabled
        :type: bool
        :Default: false

        Whether the credit-points loyalty programme is active at all. While off,
        :php:`CreditPointsLoyaltyProvider` does nothing across every step of the loyalty lifecycle —
        no balance, no redemption, no award — and the :guilabel:`Affordable` product-list mode always
        returns no products.

    ..  confval:: products.creditPoints.moneyPerPoint
        :type: number
        :Default: 0.10

        Money value of one credit point when a customer spends it at checkout, in the shop's
        currency. This is the only setting that determines what a point is worth when redeemed; how
        many points a purchase earns is controlled separately by `products.creditPoints.earningMode`
        and the per-product field.

    ..  confval:: products.creditPoints.earningMode
        :type: string
        :Default: perProduct

        How a placed order's points are calculated. One of:

        *   ``perProduct`` (the default) — each basket line earns its product's own
            :ref:`Credit points earned per unit <configuration-product-field>` value times quantity,
            summed across the order.
        *   ``basketTiered`` — the single highest-qualifying tier from
            `products.creditPoints.earningTiers` wins for the whole order; never summed with any other
            tier or with per-product values.
        *   ``autoPriceFactor`` — a basket line with no explicit per-product points value earns points
            via `products.creditPoints.priceFactor` instead of ``0``; a line that does have an explicit
            value keeps using it even in this mode.

    ..  confval:: products.creditPoints.earningTiers
        :type: stringlist
        :Default: []

        ``basketTiered`` mode's thresholds, as a list of :samp:`{amount}:{points}` pairs, e.g.
        ``50.00:10``. The highest threshold at or below the order's total wins; an entry that does not
        parse into exactly an amount and a points value is silently skipped.

    ..  confval:: products.creditPoints.priceFactor
        :type: number
        :Default: 0.0

        ``autoPriceFactor`` mode only: points earned per whole currency unit of a basket line's total,
        for lines without their own explicit per-product points value.

..  _configuration-product-field:

Per-product credit points field
================================

Every product has a :guilabel:`Credit points earned per unit` field (:sql:`credit_points` on
:sql:`tx_products_domain_model_product`), an integer defaulting to ``0``. It sits at the end of the
product's :guilabel:`Marketing` tab. An article always earns at its product's rate; there is no
separate per-article field. A product left at ``0`` never earns points regardless of
`products.creditPoints.earningMode`, and — since the :guilabel:`Affordable` listing only ever includes
products with a value greater than ``0`` — never appears in that listing either.

..  _configuration-manual-adjustments:

Manual balance adjustments
===========================

A customer's points balance is never stored directly as a single editable number — it is always the
sum of that customer's :guilabel:`Credit Points Transaction` records (storage folder record list), the
same race-free, ledger-based approach the atomic balance table underneath it enforces at the database
level. For goodwill grants or corrections outside the normal earn/redeem flow, create a
:guilabel:`Credit Points Transaction` record directly with:

*   :guilabel:`Frontend User` — the customer the adjustment applies to.
*   :guilabel:`Type` set to :guilabel:`Manual adjustment`.
*   :guilabel:`Points` — positive to grant points, negative to deduct them.
*   :guilabel:`Order UID` — left at ``0`` for an adjustment not tied to a specific order.

There is no separate approval step: any editor who can open that record list can adjust any customer's
balance.

..  _configuration-checkout-redeem-field:

Checkout redeem field
======================

Once `products.creditPoints.enabled` is on, a logged-in customer with a positive balance sees a
"spend credit points" field on the checkout review step (submitted as a plain ``spendPoints`` form
field, read back by :php:`CreditPointsLoyaltyProvider` itself — the core checkout never has to know
the field exists). The requested amount is capped by whichever is lower: the customer's balance, or
what the order's remaining goods total can actually absorb at `products.creditPoints.moneyPerPoint`,
so the payable amount never goes negative. Guests (no logged-in frontend user) never see the field and
never earn or spend points, since there is no durable identity to credit.

Placing the order records one :guilabel:`Credit Points Transaction` for the points earned on that
order and, if any were spent, a second one for the points redeemed.

..  _configuration-backend-ledger-panel:

Backend order-detail ledger panel
==================================

The backend order module's :guilabel:`Discounts & rewards` section (on an order's detail view) gains a
credit-points ledger table whenever that order has at least one
:guilabel:`Credit Points Transaction` entry — listing each entry's :guilabel:`Type` (Earned, Redeemed
or Manual adjustment), :guilabel:`Points` and date. An order with no credit-points activity shows
nothing extra; the panel contributes no empty section.
