# Vouchers for Azuriom

Vouchers is an Azuriom plugin for creating redeemable codes and granting one or more rewards to the player who redeems them.

## Reward types

- Shop points.
- Shop packages and products.
- Commands dispatched through Azuriom's native RCON or AzLink server bridges.

## Architecture

- Voucher codes are encrypted at rest and indexed through a normalized keyed hash.
- Date windows, global limits and per-user limits are stored on each voucher.
- Every voucher can contain multiple ordered rewards.
- Every redemption creates an immutable execution ledger for its rewards.
- Shop is an optional dependency; package rewards are available when Shop is enabled.
- External package and server-command delivery is claimed once; uncertain attempts require review and are never retried automatically.
- Server commands use one execution-ledger entry per command and support the safe `{player}` and `{name}` recipient placeholders.
- Recipient placeholders accept 1–64 ASCII characters: the name must start with a letter, number or underscore, then may also contain dots or hyphens. Other names are rejected before any command is sent.
- Remaining rewards continue after an uncertain attempt so that an unrelated reward is not lost; ordering is therefore not guaranteed after an interrupted external delivery.

Azuriom's scheduler must be running. Vouchers registers `vouchers:deliveries` every five minutes to process pending external rewards, reconcile abandoned claims and repair aggregate states.

A normal return from a server bridge is recorded as `dispatched`, not as confirmed execution: neither RCON nor AzLink provides an end-to-end acknowledgement. If a bridge throws after an attempt begins, the reward is marked uncertain and is never retried automatically to avoid duplicating the command. The “wait until online” option is available only for AzLink servers.

## Development status

The plugin is under active development and is not ready for production use yet.

Currently implemented:

- Secure voucher persistence and reward execution ledger.
- Administration CRUD with generated or custom codes.
- Date windows, global limits, per-user limits and authentication mode.
- Multiple ordered Shop point rewards.
- Optional Shop package/product rewards with a zero-cost payment audit trail.
- RCON/AzLink command rewards with one-attempt dispatch and an auditable uncertainty state.
- Public redemption for signed-in users or guests targeting an existing account.
- Atomic point delivery with per-request idempotency.

## Authors

- Zibuu
- Kissadere
