# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | track exposure/conversion → durable outbox messages + the ClickHouse route map | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
