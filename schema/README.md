# Bagisto API — Schema & Postman Collection

Everything a client needs to call the Bagisto REST and GraphQL APIs: machine-readable schemas, ready-to-import Postman collections, and the environments that drive them.

## What's Included

| Path | Owner | Contents |
|---|---|---|
| `generated/` | the export command | `openapi-{shop,admin}.json`, `{shop,admin}.graphql`, `graphql-operations-{shop,admin}.json` |
| `collections/` | you | `Bagisto-Shop-API` and `Bagisto-Admin-API` Postman collections |
| `environments/` | you | `Bagisto-Shop-Local` and `Bagisto-Admin-Local` Postman environments |
| `tools/` | you | `build-collection.php`, the re-seed converter |

`generated/` is rewritten by `php artisan bagisto-api-platform:export-schema`. Never hand-edit it. The command writes those six filenames and nothing else, so the other directories are never at risk.

| Surface | REST requests | GraphQL requests |
|---|---|---|
| Shop | 163 across 28 folders | 135 — 80 queries, 55 mutations |
| Admin | 329 across 16 folders | 309 — 118 queries, 191 mutations |

`graphql-operations-*.json` maps every root GraphQL field to the tag of the resource behind it. The SDL carries no tags, so this is what lets the GraphQL folder use the same grouping as REST.

## Getting Started

### 1. Import the collection

In Postman, **Import** → select `collections/Bagisto-Shop-API.postman_collection.json` (or the admin one).

Requests are foldered `REST/` and `GraphQL/`. Both use the same tag tree, so `Settings/Currencies` in the admin collection holds exactly the endpoints behind that admin screen, on either transport.

`GraphQL/` covers every root query and mutation, split into `Queries/` and `Mutations/` under each tag:

```
GraphQL/
├── Cart/
│   └── Mutations/
│       ├── createCartToken
│       ├── createAddProductInCart
│       └── …
└── Product/
    └── Queries/
        ├── product
        └── products
```

Each request ships a generated document selecting the type's scalar fields, descending through Relay connections as `edges { node { … } }`. Only **required** arguments are declared — an optional argument left null is more likely to trip validation than to help. Paginated queries also declare `first`. The full argument list for any operation is in `generated/<surface>.graphql` and in the GraphiQL playground.

### 2. Import the environment

**Environments** → **Import** → select the matching file, then fill in the blanks.

`Bagisto-Shop-Local`:

| Variable | Description |
|---|---|
| `url` | Your Bagisto URL, e.g. `http://localhost:8000` |
| `storefrontKey` | Storefront API key, from admin → Configuration → API |
| `customerEmail` | A storefront customer's email |
| `customerPassword` | That customer's password |
| `customerToken` | Set automatically by **Customer login** |
| `cartToken` | Set automatically by **Create cart token** |
| `locale` | Locale code, defaults to `en` |
| `channel` | Channel code, defaults to `default` |
| `currency` | Currency code, defaults to `USD` |

`Bagisto-Admin-Local`:

| Variable | Description |
|---|---|
| `url` | Your Bagisto URL |
| `adminToken` | Integration token, `<id>\|<plaintext>`, from admin → Settings → Integration |
| `locale` | Locale code, defaults to `en` |
| `channel` | Channel code, defaults to `default` |

Credential fields are typed `secret`, so Postman masks them and leaves them out of a shared export.

### 3. Authenticate

**Shop** — run `REST/Customer/Customer login`. It writes `customerToken`, which the collection sends as the bearer on every subsequent request. Guest cart flows instead run `Create cart token` and use `cartToken` as the bearer; the `GraphQL/Cart — *` requests already override their auth to do exactly that.

**Admin** — no login endpoint exists. Generate an integration token in the admin panel and paste it into `adminToken`. The plaintext is shown once.

## Using the raw OpenAPI instead

If you would rather generate your own client, or import the spec directly, use `generated/openapi-shop.json` / `generated/openapi-admin.json`. Both declare `{url}` as a server variable, so Postman turns it into a `url` collection variable and the shipped environments drop straight in.

The specs are surface-scoped: the storefront spec contains no admin path, schema, or tag, and vice-versa.

## Regenerating

```bash
php artisan bagisto-api-platform:export-schema
```

Options: `--path=<dir>` to write elsewhere, `--transport=all|rest|graphql` to limit the output.

The command refuses to write a spec that leaks another surface, references a schema it does not define, carries an unreachable schema, or contains a storefront key. A failure means the API surface changed in a way that needs looking at, not that the command is broken.

Regenerating is a manual step. Nothing in CI re-runs it, so after adding or changing an endpoint, run it and commit the result alongside the code.

## Contributing

Collections are curated by hand, exactly as they are consumed:

1. Import the collection and environment into Postman.
2. Make your changes there — add requests, fix bodies, improve descriptions.
3. Export the collection and replace the file under `collections/`.
4. Open a pull request describing what changed.

`tools/build-collection.php` regenerates both collections from the exported specs. It exists to re-seed from scratch and is deliberately not wired into the export command, so it can never overwrite curation. Running it discards manual edits.

```bash
php packages/Webkul/BagistoApi/schema/tools/build-collection.php
```

## What the tests guarantee

`tests/Feature/SchemaExportTest.php` runs on every export and asserts that:

- the storefront spec carries no admin schema or tag
- every `$ref` resolves and no schema is unreachable from a path
- every operation tag is declared at the top level, with its description intact
- no exported artifact contains a storefront key
- the two GraphQL schemas share no root mutation, and no root query beyond Relay's `node`
- every GraphQL request in the collections still validates against the schema it targets
- the GraphQL folder covers every root operation the schema exposes, none missing
- no GraphQL folder exists without a matching REST folder
- the collections reference no variable their environment does not declare
