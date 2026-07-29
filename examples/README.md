# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | track exposure/conversion → durable outbox messages + the ClickHouse route map | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```

The printed routes target `ab_outbox_exposures` and
`ab_outbox_conversions`. Production must apply the matching placeholder-based
DDL from `migrations/` before starting the exporter; see the main README.
