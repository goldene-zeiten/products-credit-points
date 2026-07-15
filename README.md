# TYPO3 extension `products_credit_points`

> **This repository is READ-ONLY.**
> It is split automatically out of the [goldene-zeiten/products](https://github.com/goldene-zeiten/products)
> monorepo, which is the single source of truth. Pull requests and commits made
> here are overwritten by the next split — please open them in the monorepo instead.

A credit-points loyalty programme for the [Products](https://github.com/goldene-zeiten/products-core) shop
system: customers earn points on orders and spend them at checkout for a discount, an "affordable with my
points" product listing, and a per-order points ledger in the backend order view.

## Installation

```shell
composer require goldene-zeiten/products-credit-points
```

Add the "Products Credit Points" site set, enable `products.creditPoints.enabled`, and give products a
credit-points value on their marketing tab.

## Requirements

- TYPO3 13.4 LTS or 14.3 LTS
- PHP 8.2 or newer
- `goldene-zeiten/products-core`

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
