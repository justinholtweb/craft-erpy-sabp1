# Erpy for SAP Business One

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for SAP Business One.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-sapb1
php craft plugin/install erpy-sapb1
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

A Service Layer session, established once and closed explicitly. B1 licences are consumed per session and leak if you never log out.

### Delta syncing is day-granular

B1 keeps `UpdateDate` and `UpdateTime` in separate columns and only the date is filterable, so a delta sync reads everything changed on or after the watermark’s date. Erpy’s change detection makes the re-read cheap.

### Booleans are strings

B1 answers `tYES` and `tNO`. `(bool)'tNO'` is `true`, which is how integrations end up publishing every frozen item — handled here.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/sap-business-one, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for SAP Business One is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
