# Vouchers for Azuriom

Vouchers is an Azuriom plugin for creating redeemable codes and granting one or more rewards to the player who redeems them.

## Planned rewards

- Shop points.
- Shop packages and products.
- Commands executed on a game server through RCON.

## Architecture

- Voucher codes are encrypted at rest and indexed through a normalized keyed hash.
- Date windows, global limits and per-user limits are stored on each voucher.
- Every voucher can contain multiple ordered rewards.
- Every redemption creates an immutable execution ledger for its rewards.
- Shop is an optional dependency; package rewards are available when Shop is enabled.

## Development status

The plugin is under active development and is not ready for production use yet.

Currently implemented:

- Secure voucher persistence and reward execution ledger.
- Administration CRUD with generated or custom codes.
- Date windows, global limits, per-user limits and authentication mode.
- Multiple ordered Shop point rewards.
- Public redemption for signed-in users or guests targeting an existing account.
- Atomic point delivery with per-request idempotency.

Shop package and server-command rewards are still in development.

## Authors

- Zibuu
- Kissadere
