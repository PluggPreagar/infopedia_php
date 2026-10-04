# InfoPedia PHP — Process Flow Diagrams

## 1. Routing & File Structure

```mermaid
flowchart LR
    Client([Client])

    Client -->|GET POST /entries| E[entries.php]
    Client -->|GET POST /votes| V[votes.php]
    Client -->|POST /dumps| D[dumps.php]
    Client -->|GET /files/name| F[files.php]
    Client -->|GET /health| H[health.php]
    Client -->|GET /| I[index.php]

    subgraph util["util_*.php — shared helpers"]
        U[util.php\nbootstrap]
        UE[util_entry.php\nparseEntry · sortCsvData · aggregateVotes]
        UF[util_format.php\ncsv_to_json · csv_to_txt02 · csv_to_txt03]
        UC[util_cache.php\nisCacheValid · readCache · writeCache]
        UH[util_http.php\nrespond_json · respond_error]
        UT[util_throttle.php\ncheckThrottle · throttleRetryAfter]
    end

    E & V & D & F & H --> U
    E & V --> UE & UF & UC & UT
    E & V & D & F & H --> UH

    subgraph disk["data/ — local storage"]
        CSV[entries.csv\nvotes.csv\nentries_tid.csv\nvotes_tid.csv]
        CACHE[entries.cache\nvotes.cache\nentries_tid.cache\nvotes_tid.cache]
        DUMPS[dumps.log]
        THROTTLE[throttle_sid.dat]
        OUTDATED[entries.cache.outdated]
    end

    E -->|read / write| CSV
    E -->|read / write| CACHE
    E -->|touch| OUTDATED
    V -->|read / write| CSV
    V -->|read / write| CACHE
    D -->|append| DUMPS
    UT -->|read / write| THROTTLE
```

---

## 2. GET /entries — Read Flow

_GET /votes follows the same shape; the only difference is an `aggregateVotes()` step after `sortCsvData()`._

```mermaid
flowchart TD
    A([GET /entries?format=F&tid=T&since=S&refresh]) --> B[util.php bootstrap\nsession_id · tenant_id · since]
    B --> C{tid valid?}
    C -->|no| ERR400[400 INVALID_TID]
    C -->|yes| D{format valid?}
    D -->|no| ERR400F[400 INVALID_FORMAT]
    D -->|yes| E{?refresh set?}
    E -->|yes| THR{throttle OK?}
    THR -->|no| ERR429[429 THROTTLED\nRetry-After: N]
    THR -->|yes| SKIP
    E -->|no| SKIP[ ]

    SKIP --> F{cache valid\n& no refresh?}
    F -->|yes| G[readCache]
    G --> FORMAT

    F -->|no| LP{?since set &\nfile exists?}
    LP -->|yes| POLL[long-poll\nup to 50 s\nuntil file mtime changes]
    POLL --> READ
    LP -->|no| READ[file_get_contents\ndata/entries.csv\nor data/entries_tid.csv]

    READ -->|file missing| EMPTY[return empty dataset\nTimestamp,entry header]
    READ -->|read failed| STALE{stale cache?}
    STALE -->|yes| STALERESP[serve stale cache\n+ log warning]
    STALE -->|no| ERR500[500 INTERNAL_ERROR]

    READ -->|ok| SORT[sortCsvData\nnormalise timestamps\ndedup — keep newest per path\nsort by /path/node]
    SORT --> WC[writeCache]

    WC --> DELTA{?since set?}
    DELTA -->|yes| FILTER[filter rows\ntimestamp > since]
    FILTER -->|nothing new| R204[204 No Content]
    FILTER -->|rows found| FORMAT
    DELTA -->|no| FORMAT

    FORMAT{format?}
    FORMAT -->|json| FJ[csv_to_json\nparseEntry per row\nbuild path→object map]
    FORMAT -->|csv| FC[raw CSV]
    FORMAT -->|txt.0.2| F2[csv_to_txt02\npath · timestamp · content]
    FORMAT -->|txt.0.3| F3[csv_to_txt03\nindented content only]

    FJ & FC & F2 & F3 --> R200([200 OK])
```

---

## 3. POST /entries — Write Flow

_POST /votes follows the same shape; it adds a validation step that the entry contains a `votes:<sid>:<n>` attribute._

```mermaid
flowchart TD
    A([POST /entries\nbody: entry=/path/node + attr + content]) --> B[util.php bootstrap]
    B --> C{throttle OK?\nper session_id}
    C -->|no| E429[429 THROTTLED\nRetry-After: N]
    C -->|yes| D{entry param\npresent?}
    D -->|no| E400[400 INVALID_ENTRY]
    D -->|yes| E{has at least\none pipe separator?}
    E -->|no| E400

    E -->|yes| BUG{/_/bug prefix?}
    BUG -->|yes| ROUTE[redirect to fayfBug tenant\nrecalculate source_file]
    BUG -->|no| SUFFIX
    ROUTE --> SUFFIX

    SUFFIX{last char a\ntype char .!?->-?}
    SUFFIX -->|no| APPEND[append .]
    SUFFIX -->|yes| TS
    APPEND --> TS[timestamp = now\ndate Y-m-d H:i:s]

    TS --> EXISTS{source_file\nexists?}
    EXISTS -->|no & no-tid or autocreate| CREATE[create file\nTimestamp,entry header]
    EXISTS -->|no & unknown tenant| E400T[400 INVALID_TID\nUnknown tenant]
    EXISTS -->|yes| WRITE
    CREATE --> WRITE

    WRITE[CSV-escape entry\nappend timestamp,entry to\ndata/entries.csv\nor data/entries_tid.csv]
    WRITE --> TOUCH[touchOutdated\nentries.cache.outdated]
    TOUCH --> R201([201 Created\n status:ok + timestamp])
```
