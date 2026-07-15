..  _start:

======================
Products Credit Points
======================

:Extension key:
    products_credit_points

:Package name:
    goldene-zeiten/products-credit-points

:Version:
    |release|

:Language:
    en

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

----

A credit-points loyalty programme for the Products shop system: customers earn points on orders and spend
them at checkout for a discount, an "affordable with my points" product listing, and a per-order points
ledger in the backend order view.

----

What it does
============

Once installed and enabled, each order awards the customer credit points, and the checkout review page
offers a field to redeem points against the amount still owed. Products carry a per-unit points value on
their marketing tab, and an :guilabel:`Affordable` product-list mode lists what a customer's balance
reaches, cheapest first. The backend order view gains a ledger panel showing the points earned and
redeemed for each order. Without this extension the core checkout has no loyalty programme at all.

The programme is reached only through the core's loyalty and product-list-mode contracts, so the core
stays unaware that credit points exist: the per-product points value is a column this extension owns on
the product table, and the redeem amount travels as a plain checkout field the provider reads itself.

Installation
============

..  code-block:: bash

    composer require goldene-zeiten/products-credit-points

Add the :guilabel:`Products Credit Points` site set to your site and enable it with
:confval:`products.creditPoints.enabled`. Give the products that should earn points a value on their
:guilabel:`Credit points earned per unit` field.

Settings
========

..  confval:: products.creditPoints.enabled
    :type: bool
    :Default: false

    Turn the credit-points programme on for the site.

..  confval:: products.creditPoints.moneyPerPoint
    :type: number
    :Default: 0.10

    What one point is worth when redeemed, in the shop's currency.

..  confval:: products.creditPoints.earningMode
    :type: string
    :Default: perProduct

    How points are earned: ``perProduct`` from each product's own value, or a tier-based mode.

..  confval:: products.creditPoints.earningTiers
    :type: stringlist
    :Default: []

    The spend-to-points tiers used when the earning mode is tier-based.

..  confval:: products.creditPoints.priceFactor
    :type: number
    :Default: 0.0

    Points earned per unit of order value, when earning from the order total rather than per product.
