# Production source baseline — 2026-10-09

This branch reconciles the backend source with the live application captured at `/home/sellchaze/public_html/api.sellchaze.com`.

The live Git HEAD was `d7bd318efcd349a44250b622388439f4fee09635`, but its working tree contained 36 changed tracked files and 15 new source/configuration files. All 51 are copied from the server without functional edits. They cover payments, articles and customers, atomic page/theme publication, domain hosting and SSL, sessions, currencies, and server routing configuration.

The two September 23 migrations are already recorded as applied in production. The production migration table contains 193 entries, while this reconciled source has 175 migration files. Eighteen older migration records have no corresponding file in the source examined. Reconcile that history and compare schemas in a disposable database before relying on a fresh installation or disaster recovery.

Production `.env`, credentials, database contents, vendor dependencies, runtime caches, and customer uploads are intentionally excluded. The deployed storefront shell is preserved with the frontend production artifact instead of being tracked as backend runtime storage.

The local Windows test run initially passed 499 tests and failed two path-string assertions because filesystem discovery returned backslashes. Those two assertions now compare canonical filesystem paths, and their two test classes pass all 14 tests. PHPStan passes. The initial baseline commit preserves all 51 production files exactly; the follow-up only sorts imports in `routes/api.php` for Pint and fixes those portable test assertions. It does not change production behavior.

The frontend production bundles are newer than the available React/TypeScript source. Coordinate deployment with the matching frontend baseline; do not deploy a fresh build of the older frontend source simply because this backend has been synchronized.
