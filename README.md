# Samedepo

Permanent deposit addresses with automatic crediting. No invoices, no expiring links, no blockchain infrastructure for the website owner to run.

## What Samedepo does

Samedepo replaces invoice-based and expiring-link crypto payment flows with permanent deposit addresses and automatic crediting. A website owner integrates once via the API, and from then on every customer has four reusable addresses (Bitcoin, USDT TRC20, USDT ERC20, and USDT on Base) that work forever — deposits are detected, confirmed, fee-deducted, and credited to the website owner's balance automatically.

## Who it's for

Website owners who want customers to repeatedly top up a crypto balance.

## Features

- **Owner dashboard** — real-time balances, recent activity, deposits, transaction history, and customer management.
- **Customer deposit addresses** — permanent Bitcoin, USDT TRC20, USDT ERC20, and USDT Base addresses per customer with copy-to-clipboard.
- **API keys** — generate, name, revoke, and replace owner-scoped API keys; only the hash is stored.
- **Signed webhooks** — configure an HTTPS endpoint and receive queued, HMAC-signed deposit-credit and withdrawal-status events with automatic retries.
- **Withdrawals** — set per-network withdrawal addresses, request instant or approval-mode full-balance withdrawals, estimate network fees, and track on-chain sends.
- **Admin console** — manage platform settings, withdrawal approvals, website owners, treasury balances, gas reserves, automatic top-up policies, network pauses, and public content.

## Getting started

1. `cp .env.example .env`
2. `composer install`
3. `npm install`
4. `php artisan key:generate`
5. `php artisan migrate`
6. `npm run build` (or `npm run dev` for active development)

## Tech stack

Built with [Laravel](https://laravel.com), [Livewire](https://livewire.laravel.com), [Flux Pro](https://fluxui.dev), and [Tailwind CSS](https://tailwindcss.com).

## License

[To be decided]
