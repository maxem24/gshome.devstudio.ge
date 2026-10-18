# What GS Home needs from the listings list

Date: 2026-10-15

For the Real Estate listings API. This note sums up the other files in this folder and says how GS Home uses `GET /listings`, so a different response shape still works if it covers the behavior below.

GS Home does not store listings. Every list and every card is a live read. One API key is shared by everyone using the CRM, and `GET /me` currently reports `rate_limit_per_minute` 60.

## Notes already in this folder

| File | What it asks |
|---|---|
| `listing-101864-seller-marked-as-owner.md` | Listing 101864 / ss.ge 37024554 is an agent of euro holding 2018. The API sends `is_owner: true`, `is_agency: false`, `agency_name: null`. |
| `listing-101864-source-comparison.md` | The rest of that card: the duplicate group mixes unrelated listings, the address keeps street number `0`, coordinates are the street pin with no precision flag, and `source_created_at` is the source clock labeled `+04` without conversion. |
| `list-cluster-seller-fields.md` | On each list row, every other active copy in the group needs `seller` and `contact.phone_e164`. The card endpoint already sends this under `cluster.listings[]`. The list endpoint does not. |

## What GS Home sends as of 2026-10-15

The Agencies button sends `is_agency=1`. It no longer downloads an unfiltered page and drops rows itself. Page size is `per_page=50` (the API allows 100).

`is_agency=1` was checked live on 2026-10-15: HTTP 200, a full page of agencies in about half a second. `seller=agency` did the same. An unfiltered page of 25 was 1 owner, 1 agency, and 23 others, which is why the old client-side filter added about one card per request.

## How each button calls the list

The viewer's allowed deal types always go in `deal_type_id`. A person who works both sale and rent sends both ids. Other screen filters (price, city, district, area, rooms, property type, source, dates, sort, text search) are added as documented. Empty values are omitted.

| Button | Query | What we do with the rows |
|---|---|---|
| All | No seller filter. `only_primary` stays at its default, `1`. | Show every returned row. The ownership badge is a phone match, not a seller flag. |
| Owners | `is_owner=1` | Show the page the API returns. |
| Agencies | `is_agency=1`. If the "owners only" toggle was on, `is_owner` is removed. | Show the page the API returns. We do not filter these rows again. |
| Our agency | `phones` = phones of the signed-in person's company. The group owner has no company, so this is every active company. Linked companies are not included. `exclude_phones` is removed. `only_primary` stays `1`. | Show the primary listing of each group that contains one of those phones. |
| Mine | `phones` = phones held by the signed-in person, `only_primary=0` | Keep a row only when `contact.phone_e164` is one of those phones. Sibling copies in the same group are dropped. If a page adds no such row, we follow `next_cursor`, at most 8 pages, then stop. |

Search `q` is the screen search box: trimmed, at most 100 characters, sent only when it is at least 2 characters. Your API searches the title and address. We do not search the description ourselves.

## How a seller becomes a label

We read `seller` in this order:

1. `is_owner` true → Owner.
2. Otherwise `is_agency` true, or `agency_name` filled → Agency.
3. Otherwise → Agent (a private broker, not our company).

`is_owner` wins. An agency listing stored as `is_owner: true` is labeled Owner and is excluded from Agencies, because that button now trusts `is_agency=1`. Setting `agency_name` without `is_agency: true` still displays as Agency on a row we already have, but that row will not be returned by `is_agency=1`. Please set the flag from the company on the source page (`initialAgentInfoData`, `externalCompanyId`), not from a null top-level `agencyName`.

## How "ours" is decided

Our companies and employees are phones we store in E.164. A listing is ours when `contact.phone_e164` or any number in `cluster.phones_e164` matches one of those phones. `agency_name` is not used for this. A masked phone (`555402***`, `phone_e164` null) never matches.

When the visible row is not an owner, the group has at least two listings, the list did not include each copy's seller, and one of the group's phones is ours, we call `GET /listings/{id}` to learn which copy is the owner. At most five such calls per page. That is what `list-cluster-seller-fields.md` removes. With those fields on the list row we can show both facts without the second request: this company has a listing in the group, and the owner still has their own listing on the site.

## What still has to change on the API side

1. **Seller flags must match the source.** Listing 101864 is the example. The same check is needed for any other ss.ge agent listing stored as the owner. Until that is fixed, Agencies and Owners are fast but some agency listings stay on the wrong button.

2. **List rows need each copy's seller.** Send, for every other active listing in the group, `id`, `seller.is_owner`, `seller.is_agency`, `seller.agency_name`, and `contact.phone_e164`. The card already has this. Details and the reason are in `list-cluster-seller-fields.md`.

3. **Raise this client's rate limit toward 600 per minute.** Your API doc says a live client that calls the API on every screen should be about 600. This key is 60. It is an admin setting on the API client. One person scrolling is fine at 50 rows per request. Several people on the same key are not.

4. **Optional: return only the matching row for Mine.** With `only_primary=0`, `phones` returns every active listing in a matched group, including copies whose contact is not in the list. We drop those. When a page is all siblings, we spend another request to find one row, up to 8 times. A parameter that returns only listings whose own `contact.phone_e164` is in `phones` would remove that loop. Any name is fine. If you would rather keep `only_primary=1` and point us at the matched copy, that also works, as long as Mine shows the signed-in agent's own ad and not the owner's primary in the same group.

## Behavior to keep if you choose another shape

- Agencies: one request returns a page of agency sellers. `next_cursor` pages that same set.
- Owners: one request returns a page of owners.
- Our agency: groups that contain our company's phones. The row may be the group's primary listing.
- Mine: the signed-in person's own listing rows, not a colleague's copy and not the owner's copy in the same group.
- All: one primary per group, plus enough on the list row to say "our company is in this group" and "the owner still has a listing" without `GET /listings/{id}`.
- A true `is_owner` is what we display as Owner, so that flag has to mean the source listing is the owner's.

We do not need a total count, ss.ge dictionary ids, or a local copy of the database. `per_page` 50 is enough. The refresh endpoints (`POST /listings/{id}/refresh`, `GET /listing-refreshes/{id}`) are a separate button and are not part of list speed.
