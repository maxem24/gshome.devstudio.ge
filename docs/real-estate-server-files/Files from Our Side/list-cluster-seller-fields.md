# List rows should include each duplicate's seller

Date: 2026-10-15

For the Real Estate listings API. This is a request from GS Home. The card endpoint already has the data. The list endpoint does not, so GS Home calls the card again.

## What the list returns today

`GET /listings` puts a duplicate group on each row as:

- `cluster.id`
- `cluster.listings_count`
- `cluster.phones_e164`
- `cluster.matched_listing_ids`, and only when the request used `phones`

`GET /listings/{id}` already adds `cluster.listings[]` for the other active copies: `id`, `source`, `source_url`, `is_primary`, `seller`, `contact` (with `phone_e164`), `price`, `updated_at`.

## What is missing

On each list row, for every other active listing in the group, send the same seller and contact the card already sends:

- `id`
- `seller.is_owner`
- `seller.is_agency`
- `seller.agency_name`
- `contact.phone_e164`

## Why this is an extra request

On All listings, an agent must see two facts when one copy is the owner's and another is ours: this company has the listing, and the owner still has another listing on the site. Phone numbers alone do not say which copy is the owner's. GS Home then calls `GET /listings/{id}` for that row, up to five cards per page.

Those card calls repeat work the list assembler already did, spend the client's rate limit, and are the gap between the two platforms. The fields above on the list row remove the second request.

## What we are not asking to change

`only_primary` can stay `1` by default. For the signed-in agent's own ads, GS Home sends that agent's phones and `only_primary=0`, then keeps only rows whose contact phone is that agent's. That button does not need this cluster change.
