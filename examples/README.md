# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | stable event ids, pseudonymous aggregates, filtered context, and ClickHouse routes | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```

The printed routes target the schema v2 tables `ab_exposures_v2` and
`ab_conversions_v2` owned by `yii3-ab-testing-clickhouse`. Production creates
them with that package's `SchemaMigrations::apply()` before starting the
exporter; see the main README.
