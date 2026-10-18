# Listing 101864 compared with the ss.ge source

Date: 2026-10-15

Compared one listing end to end:

| | |
|---|---|
| Real Estate id | 101864 |
| Source application id | 37024554 |
| Source URL | https://home.ss.ge/ka/udzravi-qoneba/qiravdeba-3-otaxiani-bina-did-dighomshi-37024554 |
| GS Home card | https://gshome.local/admin/listings/101864 |

Checked `GET /listings/101864` (HTTP 200, 2026-10-15) against the raw ss.ge page payload for application `37024554`. The large `locations` tree in that payload is the site-wide city dictionary, not this listing. It was used only to confirm the street pin for street id `1404`.

GS Home does not store listings. The card is whatever this response contains, after our mapping. Two kinds of gap are separated below: the API is wrong or omits a source field, and the API already sends a field that GS Home does not show.

The seller mistake on this card is already written up in `listing-101864-seller-marked-as-owner.md`. This note is the rest of the field comparison.

## What matches

These values are the same on ss.ge and in the API, and GS Home shows them.

| Field | Source | API |
|---|---|---|
| Status | Active | `active` |
| Deal | ქირავდება (`realEstateDealTypeId` 1) | rent, title ქირავდება |
| Type | ბინა (`realEstateTypeId` 5) | apartment, title ბინა |
| City / district / subdistrict | თბილისი / ვაკე-საბურთალო / დიდი დიღომი | same titles |
| Street | 4000 მესხის ქ. (street id 1404) | same street name in `address` |
| Area | 90 | `area_total` 90 |
| Rooms / bedrooms / toilet | 3 / 2 / 1 | `rooms` 3, `bedrooms` 2, `bathrooms` 1 |
| Floor | 7 of 9 | `floor` 7, `floors_total` 9 |
| Balconies | `balcony_Loggia` 2 | `balconies` 2 |
| Condition | ძველი რემონტით | `condition` ძველი რემონტით, slug `old_renovation` |
| Building | ძველი აშენებული | `building_status` ძველი აშენებული, slug `old_building` |
| Price | 700 USD, 1820 GEL, 8 USD/m², 20 GEL/m² | same, `negotiable` false |
| Title (ka) | ქირავდება 3 ოთახიანი ბინა დიდ დიღომში | same |
| Description (ka) | ორი თვის წინასწარი გადახდით | same |
| Images | 7 files, same URLs, order 0–6, none marked main | same 7 URLs and order, all `is_main` false |
| Contact | გიგა, 511102375, WhatsApp yes, Viber no | same name, phone, `has_whatsapp` true, `has_viber` false |
| Metro | `nearbySubwayStations` empty, map subway null | `subway_stations` empty |
| Expiry | `endDate` 2026-11-29 13:46:32 +04 | `source_expires_at` same clock time |

Dictionary ids differ (ss.ge city 95 / district 3 / subdistrict 45, API city 1 / district 1 / subdistrict 26). Titles match. Those ids should stay on the Real Estate dictionary. Do not renumber them to ss.ge ids.

Amenities that are true on ss.ge are all present as slugs: elevator, gas, internet, tv, furniture, conditioner, heating, hot water, washing machine, fridge, storage, iron door, cable TV, balcony, glazed windows. Flags that are false on ss.ge are omitted. That is the right shape. Empty source values are correctly null: kitchen area, yard area, ceiling height, build year, videos, cadastral code, listing pin.

## Discrepancies

### 1. Seller is stored as the owner

The API sends `seller.is_owner: true`, `is_agency: false`, `agency_name: null`. GS Home trusts `is_owner` first, so the card shows Owner (მესაკუთრე).

The source is an agent of a company, not the owner:

| Source field | Value |
|---|---|
| `initialAgentInfoData.companyId` | 2618 |
| `initialAgentInfoData.companyName` | euro holding 2018 |
| `initialAgentInfoData.companyType` | 2 |
| `initialAgentInfoData.infoType` | 1 |
| `initialAgentInfoData.agentContactName` | გიგა |
| `applicationData.externalCompanyId` | 2618 |
| `applicationData.userApplicationCount` | 161 |

`userEntityType` is `Individual`, and the top-level `agencyId`, `agencyName`, `companyId`, and `companyName` on `applicationData` are null. Those fields are not enough to decide the seller. The company block above is the one that names the agency.

Please set `is_owner` to false, set `is_agency` to true, and set `agency_name` to `euro holding 2018`. Details and the display effect are in `listing-101864-seller-marked-as-owner.md`.

### 2. The duplicate group is not this apartment

`cluster.listings_count` is 11. The other 10 rows are different listings: other districts (Vake, Gldani, Varketili, Nadzaladevi), other prices (385–1100 USD), and other phones. None of them is 4000 მესხის ქ. in დიდი დიღომი at 700 USD.

ss.ge puts this application in `similarityGroup` 37021582. The API does not send that id, and the cluster members are not copies of this flat. GS Home shows that cluster as the duplicate group (our card on one side, source links on the other), so unrelated listings appear as the same property.

Please cluster only listings that are the same property, and send the source similarity group id when ss.ge has one.

Every cluster member on this card has `is_agency: false` and `agency_name: null`. That is the same classification hole as on 101864, but those rows were not compared with their own ss.ge pages. Do not treat this note as proof that each of them is mislabeled.

### 3. House number is the placeholder `0`

Source `address.streetNumber` is `"0"`. The API address is `4000 მესხის ქ. 0`. There is no real house number. Please omit the street number when it is `0` or empty, or send `street_number` separately and leave it null in this case.

### 4. Coordinates are the street centroid, not a listing pin

Source `locationLatitude` and `locationLongitude` are null, and `isManualPin` is false. The API `lat` / `lng` are `41.784904` / `44.765335`, which are `streetLocationLatitude` / `streetLocationLongitude` for street id 1404. The values are right for the street. They are not a pin on the building. Please add a flag such as `coordinates_precision: street` when the pin was taken from the street rather than from the listing.

### 5. Created time is shifted by four hours

Source `orderDate` is `2026-10-15T13:46:32.266385Z` (13:46 UTC). The API `source_created_at` is `2026-10-15T13:46:32+04:00`, which is 09:46 UTC. The clock time was copied and labeled `+04` without conversion. `endDate` is already `+04` and matches `source_expires_at`, so only the created timestamp is wrong. Please store `orderDate` as the UTC instant it is.

## Source fields the API does not send

Worth adding, in this order:

| Source field | This listing | Why |
|---|---|---|
| Company id, name, type, logo | 2618, euro holding 2018, type 2, logo URL on `initialAgentInfoData` | Needed for the seller block in discrepancy 1. Logo URL is optional once the name is present. |
| `userApplicationCount` | 161 | A publisher with this many applications is an agent. Useful when the company block is easy to miss. |
| `similarityGroup` | 37021582 | Lets us check cluster membership against ss.ge. |
| `project` | ქალაქური | Building project type. We have no field for it today. |
| `priceLevel` | Expencive | Relative price on ss.ge. Optional. |
| Coordinate precision | listing pin null, street pin set | Discrepancy 4. |
| `streetNumber` as its own field | `"0"` | Discrepancy 3. |

Lower priority. Send only if cheap; we do not show them today:

| Source field | This listing |
|---|---|
| `mortgagePercentages` | BOG 13.4 / 14.85, TBC 12.1 / 14.21, Tera 13.7 / 14.3, HBG 11 / 11.8 (nominal / effective) |
| `viewCount` | 17 |
| `hasRemoteViewing`, `isPetFriendly`, `isForUkraine`, `isExclusive`, `isUrgent`, `vipStatus` | all false or `None` |
| `cadastralCode`, `mapInfo`, listing videos | null or empty |

Do not send the `locations` dictionary with the listing. It is not listing data.

## API fields GS Home does not show

These are already on `GET /listings/101864`. No partner change is required. They are listed so a missing value on the card is not read as a missing API field.

| API field | This listing | What we do |
|---|---|---|
| `price.per_sqm_usd`, `price.per_sqm_gel` | 8 and 20 | Not mapped. The card shows 700 USD and 1820 GEL only. |
| `source_expires_at` | 2026-11-29 13:46:32 +04 | Not mapped. |
| `source_updated_at` | null | Not mapped. Nothing to show on this listing anyway. |
| `condition_slug`, `building_status_slug` | `old_renovation`, `old_building` | Not mapped. The Georgian titles are shown. |
| Cluster member `price.gel` | present on each row | The duplicate row keeps USD only. |
| Image `order` | 0–6 | The array order is kept; the order number is not stored. None of these images is `is_main`. |

## Changes to make on the partner side

1. Correct seller on 101864: `is_owner` false, `is_agency` true, `agency_name` `euro holding 2018`. Read the company from `initialAgentInfoData` and `externalCompanyId`, not from `userEntityType` or the null `agencyName` on `applicationData`.
2. Stop clustering unrelated listings into this card’s duplicate group. Send `similarityGroup` when ss.ge has one.
3. Do not append street number `0`.
4. Mark coordinates that come from the street centroid.
5. Store `orderDate` as UTC instead of relabeling it `+04`.
6. Optionally add `project` (ქალაქური here) and publisher application count.

Items 1–5 change data we already show or group. Item 6 is new information we would have to map before it appears on the card.
