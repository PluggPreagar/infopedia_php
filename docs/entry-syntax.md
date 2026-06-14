# Entry Syntax — supported input/output

This file documents the entry line formats currently supported by `util_entry.php`.
It is executable-spec backed by `tests/entry_syntax_test.php`.

## 1. Old Google Sheet CSV input

```csv
<timestamp>,"<topic> | <node> | <message>"
```

Example:

```csv
14/09/2025 07:17:33,"/clima | biz | Some fact."
```

Parsed as:

| Field | Value |
|---|---|
| `timestamp` | `14/09/2025 07:17:33` |
| `topic` | `/clima` |
| `node` | `biz` |
| `content` | `Some fact.` |
| `entry_type` | last character of content (`.` here) |
| `delete` | `true` only when content is `--` |

### Old-format delete marker

```csv
14/09/2025 07:17:33,"/clima | biz | --"
```

A delete row removes the latest aggregate for the same `<topic> | <node>` key.

### Old-format multiline messages

Quoted CSV may wrap across physical lines. `sortAndDeduplicateCsv()` aggregates lines
until the quote count is even, then parses with `str_getcsv()`.

## 2. New 0v02-style input/output

```csv
<path>/<node>,[<attribute>:<value>,...],<timestamp>,<message>[,<vote>]
```

Example:

```csv
/clima/biz,2025-09-14 07:17:33,Some fact.
```

With attributes before the timestamp:

```csv
/clima/biz,sign:bhfhjjjk,2025-09-14 07:17:33,Some fact.
/clima/biz,vote:+1,sign:abc123,2025-09-14 07:17:33,Some fact.
```

Parsed as:

| Field | Value |
|---|---|
| `topic` | `/clima` |
| `node` | `biz` |
| `timestamp` | `2025-09-14 07:17:33` |
| `content` | `Some fact.` |
| `vote` | optional fourth CSV column |

Attributes are optional `key:value` tokens placed between `<path>/<node>` and
`<timestamp>`. Supported keys are intentionally open-ended (`sign`, `vote`, etc.);
keys must start with a letter and may contain letters, digits, `_`, or `-`.
When `vote:...` appears as an attribute, it is also exposed as parsed field `vote`.

Literal `\n` inside `<message>` is decoded to a real newline when parsing 0v02 input.
`formatEntry()` emits real newlines as literal `\n`.

## 3. Output from `formatEntry()`

Input parsed from old format:

```csv
14/09/2025 07:17:33,"/clima | biz | Some fact."
```

Output:

```csv
/clima/biz,14/09/2025 07:17:33,Some fact.
```

## 4. Sorting and aggregation output

`sortAndDeduplicateCsv()`:

- normalizes CRLF to LF
- parses old Sheet CSV rows
- keeps only the latest row per `topic | node` key
- removes keys whose latest row has message `--`
- sorts keys lexicographically
- appends a synthetic `/_/menu/Most-Recent-Entry` row for the latest non-hidden row

## 5. Not supported by these helpers

- arbitrary delimiters other than CSV comma + ` | ` inside old entry column
- JSON input lines
- malformed old rows with fewer than `topic | node | message`

