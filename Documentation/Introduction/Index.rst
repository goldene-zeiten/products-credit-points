:navigation-title: Introduction

..  include:: /Includes.rst.txt
..  _introduction:

============
Introduction
============

EXT:products_credit_points adds a credit-points loyalty programme to EXT:products_core's checkout.
Customers earn points on the products they buy and can later spend points for a discount on a future
order — the round trip that distinguishes a loyalty programme from a plain discount. The core shop
itself knows nothing about credit points; it only knows about its loyalty and product-listing seams,
which this extension is one implementation of.

..  contents:: Table of contents
    :local:

..  _introduction-what-it-provides:

What it provides
=================

*   A :guilabel:`Credit points earned per unit` field on every product — a column this extension owns
    on the core's product table, read with a direct query rather than an Extbase property the core
    does not have.
*   :php:`GoldeneZeiten\Products\CreditPoints\Loyalty\CreditPointsLoyaltyProvider`, registered against
    the core's loyalty seam with the identifier ``credit_points``. It computes a customer's balance,
    quotes and applies a requested redemption, and awards the points a placed order earned.
*   An :guilabel:`Affordable` product-list mode
    (:php:`GoldeneZeiten\Products\CreditPoints\Catalog\AffordableProductListModeProvider`) that lists
    what a logged-in customer's balance can afford, cheapest first — placeable in a product-list
    content element like any other listing.
*   A "spend credit points" field on the checkout review step, added by overriding the core review
    page's loyalty slot partial and populated through the core's review-enrichment event.
*   A credit-points ledger panel in the backend order-detail view, added through the core's
    order-detail-panel seam, showing the earn/redeem/adjustment entries for that order.

See :ref:`Configuration <configuration>` for every field and setting, and :ref:`Developer <developer>`
for exactly which core interfaces and events each of the above uses.

..  _introduction-loyalty-seam:

Relationship to the core's loyalty seam
=========================================

EXT:products_core is loyalty-agnostic: it defines
:php:`GoldeneZeiten\Products\Core\Loyalty\LoyaltyProviderInterface` and collects implementations in
:php:`GoldeneZeiten\Products\Core\Loyalty\LoyaltyRegistry`, but ships no programme of its own. Loyalty
is on-top functionality — a shop with no loyalty provider installed still checks out normally, exactly
as it did before this extension existed. :php:`CreditPointsLoyaltyProvider` is nothing more than one
implementation of that interface; the checkout reaches it only through the registry, which is what
would let it move to a different points scheme, or be removed entirely, without the core changing.

The same pattern holds for the two smaller seams the extension uses: the backend ledger panel is one
implementation of :php:`OrderDetailPanelInterface`, and the checkout redeem field is populated by a
listener on :php:`EnrichCheckoutReviewEvent` — both dispatched by the core to whichever add-ons, if
any, are installed. See :ref:`Developer <developer>` for all three.

..  _introduction-when-to-use:

When to use this extension
============================

Install it whenever a shop wants to reward repeat customers with a simple points-based loyalty
mechanic without maintaining a separate rewards system. A shop that has no need for a loyalty
programme, or that wants a different one (cashback, tiered rewards, ...), simply does not install
this extension, or replaces it with its own :php:`LoyaltyProviderInterface` implementation — the core
shop works identically either way.
