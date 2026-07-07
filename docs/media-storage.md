# Media storage, per-site isolation, and site deletion

All site media (try-on source photos + results, banner reference uploads + generated banners,
PDP preview snapshots) lives on **one S3-compatible object store**, addressed by a tenant-scoped
path. The DB (Railway Postgres) holds the structured content; this doc covers the **files**.

## Where media lives — an S3-compatible bucket (config, not code)

The media disk (`config/filesystems.php` → `s3`) is a generic **S3-compatible** driver, fully
env-driven, so it works with Cloudflare R2, AWS S3, a Railway-provisioned bucket, or MinIO — the
same code across the web / worker / scheduler services (a shared bucket, NOT a per-service disk).

> A raw Railway **Volume** is a local disk mounted to ONE service, so the worker could not read
> what the web service wrote — it does not fit the three-service split. Use an S3-compatible
> **bucket** (Cloudflare R2 is the cheapest fit: zero egress; a Railway bucket or S3 also work).

Env contract (set on every service — web, worker, scheduler):

| Var | Meaning |
|---|---|
| `MEDIA_DISK` | `s3` (the default) |
| `S3_KEY` / `R2_KEY` | access key id |
| `S3_SECRET` / `R2_SECRET` | secret access key |
| `S3_BUCKET` / `R2_BUCKET` | bucket name |
| `S3_REGION` / `R2_REGION` | region (`auto` for R2) |
| `S3_ENDPOINT` / `R2_ENDPOINT` | the bucket endpoint (R2: `https://<acct>.r2.cloudflarestorage.com`) |
| `S3_USE_PATH_STYLE_ENDPOINT` | `true` for R2/MinIO |
| `MEDIA_CDN_URL` | public read base in front of the bucket (edge/egress control) — used for public banner URLs |

No code change is needed to switch providers — only these env vars.

## Per-site isolation — a shopper can never see another site's media

Every object path **leads with the account then the site**, and account_id is the first path
segment so an object can never be cross-tenant ambiguous:

```
accounts/{account_id}/sites/{site_id}/generations/{generation_id}/{source|result}-{rand}.ext
accounts/{account_id}/sites/{site_id}/banners/{asset_id}/{banner-source|banner}-{rand}.ext
accounts/{account_id}/sites/{site_id}/previews/{url_hash}.html
```

- **Private by default.** Try-on inputs/results + preview snapshots are written `private`; the
  browser only ever receives a **short-lived signed URL** for a single object (`MediaStorage::signedUrl`,
  TTL from `trayon.media.signed_ttl`). A signed URL grants access to that ONE object — never a
  listing, never a sibling. The app only ever mints a signed URL for an object it fetched through
  an account-scoped query, so one site cannot obtain another site's object path or signature.
- **Banner artwork is public** (marketing shown to every shopper) but the leaf carries a 24-char
  random token, so paths are unguessable and there is no bucket listing.
- No public bucket listing is ever exposed; reads go through the CDN base (`MEDIA_CDN_URL`).

## Deleting a site — DB rows AND media are removed

Deleting a `Site`:

1. **DB rows cascade** — every site-scoped table (`generations`, `banners`, `banner_assets`,
   `banner_events`, `end_users`, `products`, `activity_events`, …) has `site_id` (and `account_id`)
   as `foreignId(...)->constrained()->cascadeOnDelete()`, so the rows vanish with the site.
   Account-level money records (`credit_ledger`) have no `site_id` and are **kept** (deleting one
   shop must not erase the account's financial history).
2. **Media is purged** — `Site::deleted` dispatches `PurgeSiteMediaJob(account_id, site_id)` (on
   the `media` queue), which deletes the whole `accounts/{account}/sites/{site}/` prefix from the
   bucket (`MediaStorage::purgeSite`). The prefix leads with account_id + site_id, so the purge can
   only ever remove that ONE site's objects — proven by `tests/Feature/Media/SiteMediaPurgeTest.php`
   (a sibling site under the same account, and another account's site, are both untouched).

Net: removing a shop leaves no orphaned rows and no orphaned files — and never touches another shop.
