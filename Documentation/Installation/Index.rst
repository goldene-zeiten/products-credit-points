:navigation-title: Installation

..  include:: /Includes.rst.txt
..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

*   TYPO3 13.4 LTS or 14.3
*   PHP 8.2, 8.3, 8.4 or 8.5
*   EXT:products_core (``goldene-zeiten/products-core``), for the shop and its loyalty and
    product-listing seams

..  _installation-composer:

Installation with Composer
===========================

..  code-block:: bash

    composer require goldene-zeiten/products-credit-points

Then activate the :guilabel:`Products Credit Points` site set (``goldene-zeiten/products-credit-points``)
on the site(s) that should offer the loyalty programme, and turn it on with
:confval:`products.creditPoints.enabled` — see :ref:`Configuration <configuration>` for that and every
other setting. Give the products that should earn points a value on their own
:guilabel:`Credit points earned per unit` field; a product left at ``0`` never earns anything.

Until :confval:`products.creditPoints.enabled` is turned on, installing this extension has no visible
effect: no balance is shown, no points are earned or spent, and the checkout behaves exactly as it did
without the extension.
