CREATE TABLE IF NOT EXISTS {{outbox_exposures_table}}
(
    event_id     String,
    event_at     DateTime('UTC'),
    experiment   String,
    variant      String,
    subject_id   String,
    is_forced    UInt8 DEFAULT 0,
    is_fallback  UInt8 DEFAULT 0,
    is_sticky    UInt8 DEFAULT 0,
    environment  String DEFAULT '',
    ingested_at  DateTime('UTC') DEFAULT now()
)
ENGINE = ReplacingMergeTree
PARTITION BY toYYYYMM(event_at)
ORDER BY event_id
