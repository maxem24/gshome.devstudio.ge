# Listing 101864 is marked as the owner, but ss.ge shows an agent

Date: 2026-10-15

For the Real Estate listings API. GS Home displays the seller type it receives. On this listing the source site and the API disagree, and the API is the side that is wrong.

## Listing

| | |
|---|---|
| Real Estate id | 101864 |
| Source | ss.ge |
| Source URL | https://home.ss.ge/ka/udzravi-qoneba/qiravdeba-3-otaxiani-bina-did-dighomshi-37024554 |
| GS Home card | https://gshome.local/admin/listings/101864 |

Checked against `GET /listings/101864` on 2026-10-15. The response was HTTP 200.

## What the source site shows

The ss.ge page for this URL is an agent listing. The publisher is Giga (გიგა), and the agency shown on the page is euro holding 2018. It is not marked as the owner.

## What the API sends

```json
"seller": {"is_owner": true, "is_agency": false, "agency_name": null}
```

`contact.name` is გიგა, which matches the person on ss.ge. `agency_name` is null, so the agency name from the source page is missing as well.

The other copies in this duplicate group are not marked as the owner. Their seller block is `is_owner: false`, `is_agency: false`, `agency_name: null`. Only listing 101864, the one whose `source_url` is the page above, is flagged as the owner.

## What GS Home does with that

GS Home does not decide the seller type itself. If `seller.is_owner` is true, the card shows “Owner” (მესაკუთრე). That is why this card says the listing is from the owner.

Please correct listing 101864 so `is_owner` is false, and so an agent listing that names an agency is not stored with `is_agency: false` and `agency_name: null`. The same check is needed for any other ss.ge agent listing that was stored as the owner.
