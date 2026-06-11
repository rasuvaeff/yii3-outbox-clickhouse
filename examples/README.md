# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `export.php` | record → route → grouped batched export (prints inserts, fake writer) | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/export.php
```

For a real ClickHouse, swap the fake writer factory for
`DefaultClickHouseWriterFactory(clientFactory: new ClickHouseClientFactory(...))`
and point routes at `ReplacingMergeTree` tables keyed by `event_id` — see the
main [README](../README.md).
