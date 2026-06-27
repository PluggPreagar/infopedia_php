# German Discourse Analysis — Design Spec

**Date:** 2026-06-27
**Branch:** `feature/german-lang-analysis`
**Affects:** new standalone Python tool · `data.php` (new `analysis` entity) · optional future `statistic.html` extension

---

## 1. Goal

Extract two complementary layers of meaning from German-language text and feed the results into InfoPedia's data channel for comparison and visualisation.

**Layer 1 — Propositional content:** who does what to whom (*Subject–Verb–Object*, normalised).
**Layer 2 — Rhetorical intent:** how and why it is being said — scored across a taxonomy of argumentative and emotional dimensions grounded in argument mining research.

Primary use case: distinguish constructive, evidence-based discourse from manipulative, fallacious, or emotionally coercive argumentation across statements attributed to known speakers on a shared topic.

**Out of scope for v1:** real-time analysis, training custom ML classifiers, session-protocol frontend UI.

---

## 2. Scientific Framework

The tool sits within **Argument Mining** — the NLP subfield that extracts argumentative structure, classifies claim types, detects fallacies, and assesses reasoning quality from natural language.

Four theoretical frameworks anchor the dimension taxonomy:

| Framework | Authors | Contribution to this tool |
|---|---|---|
| **Pragma-dialectics** | Van Eemeren & Grootendorst | Defines reasonable discourse; classifies deviations as *strategic manoeuvring* or *discourse derailment*; names rule violations (freedom, standpoint, burden-of-proof, relevance, unexpressed-premise, starting-point, validity, argument-scheme, closure, usage) |
| **Appraisal Theory** | Martin & White | Scores *attitude* (positive/negative evaluation of people, feelings, things), *engagement* (acknowledging vs. dismissing other positions), *graduation* (force/intensity, focus/absolutism) |
| **Speech Act Theory** | Austin, Searle | Classifies *illocutionary force*: assertive (claims), directive (commands/demands), commissive (promises/threats), expressive (emotions), declarative |
| **Classical Fallacy Taxonomy** | Aristotle → Hamblin → Walton | Named fallacies: *ad metum*, *ad hominem*, *ad ignorantiam*, *ad populum*, *tu quoque*, *bifurcation*, *straw man*, *ad verecundiam* (false authority) |

Emotion scoring uses **Plutchik's wheel of emotions** (8 basic emotions) operationalised via the NRC Emotion Lexicon (translated German) and **SentiWS** (Leipzig; valence + intensity weights for ~3,500 German lemmas).

---

## 3. Two-Layer Analysis Model

### 3.1 Layer 1 — Propositional Content (SVO)

Per sentence, one normalised triple plus structural metadata.

| Field | Type | Description |
|---|---|---|
| `subject_lemma` | string | Lemmatised nominative agent (`nsubj` in UD tree); pronoun or noun head |
| `verb_lemma` | string | Semantic main verb lemma, resolved through auxiliary chain |
| `verb_tense` | enum | `praesens` · `praeteritum` · `perfekt` · `futur` · `modal` · `passiv` |
| `verb_modality` | string\|null | Modal operator if present: `können` `müssen` `sollen` `dürfen` `wollen` |
| `object_lemma` | string\|null | Lemmatised accusative/dative patient (`obj`/`iobj` in UD tree) |
| `negated` | bool | Negation particle (`nicht`, `kein-`) scoping over verb |
| `speaker_role` | enum | `1st_sg` · `1st_pl` · `2nd_sg` · `2nd_pl` · `3rd` — derived from subject pronoun |

**German-specific verb resolution (spaCy UD dependency labels):**

| Construction | Example | Resolution |
|---|---|---|
| Perfekt | *ich habe das getan* | `haben`/`sein` = `aux`; past participle = `ROOT` → verb_lemma = participle lemma |
| Futur I | *ich werde das tun* | `werden` = `aux`; infinitive = `ROOT` → verb_lemma = infinitive |
| Modal | *ich kann das machen* | modal = `ROOT`; infinitive = `xcomp` → verb_lemma = infinitive, modality = modal |
| Passiv | *das wurde getan* | `werden` = `aux:pass`; participle = `ROOT` → passiv = true |
| Separable verb | *ich rufe an* | particle (`compound:prt`) + verb head → concatenated lemma: `anrufen` |
| Reflexive | *ich freue mich* | reflexive pronoun as `obj`; verb stays; mark as reflexive |

### 3.2 Layer 2 — Rhetorical/Emotional Dimensions

Not a single label. Each sentence receives a **scored vector** — every dimension independently `float 0.0–1.0`. A sentence can score 0.8 accusation + 0.5 fear appeal simultaneously. Primary label = dimension with highest score above threshold `0.4`, or `none`. Secondary label = second-highest dimension above `0.3`, or `none`.

Detection is **lexicon + rule-based** (deterministic given the parsed input): no ML classifier in v1.

#### Constructive dimensions

| Dimension | Scientific anchor | German surface markers | Detection |
|---|---|---|---|
| `logos` | Epistemic argumentation; Toulmin *grounds + warrant + backing* | *weil, da, folglich, daher, Belege zeigen, Studien, Forschung, möglicherweise, laut* | Causal connectives + factual hedging lemmas |
| `ethos_legitimate` | Aristotelian ethos; legitimate *argumentum ad verecundiam* | *laut Experten, Forschung zeigt*, cited named source | `ethos` verb + named source NP |
| `pathos_empathic` | Benevolent pathos; supportive communication (Burleson); person-centred validation (Rogers) | *ich verstehe, ich kann nachvollziehen, das ist schwierig, deine Angst ist berechtigt* | Empathy verb lemmas (`verstehen`, `nachvollziehen`) + 2nd-person target |
| `engagement_open` | Appraisal engagement — dialogic expansion | *einerseits...andererseits, man könnte auch sagen, ich sehe das anders weil* | Concessive markers + counter-argument acknowledgment |

#### Manipulative / fallacious dimensions

| Dimension | Scientific anchor | Fallacy code | German surface markers | Detection |
|---|---|---|---|---|
| `pathos_manipulative` | Malevolent pathos; *argumentum ad metum*; catastrophising (Witte EPPM) | ad metum | *wenn ihr nicht X tut dann Y [extreme outcome], alles verloren, drohen, warnen vor* | Conditional + negative-extreme consequence vocab |
| `blame_attribution` | Attribution theory (Weiner external attribution); face-threatening act attacking negative face (Brown & Levinson) | ad hominem (personal) | *du bist schuld, wegen dir/euch, du hast immer, ihr habt das verursacht* | Blame lemmas (`schuld`, `verantwortlich`) + 2nd-person agent |
| `ad_hominem` | Pragma-dialectics freedom rule violation — attacking speaker instead of argument | ad hominem (abusive) | *du verstehst das nicht, ihr seid zu dumm, wer bist du denn* | Attack verb + speaker NP (not claim NP) |
| `ad_ignorantiam` | Epistemic coercion (Fricker *epistemic injustice*); exploiting knowledge asymmetry | ad ignorantiam | *das ist zu komplex für euch, ihr wisst das nicht, man muss Experte sein um* | Complexity/knowledge-gate framing + 2nd-person exclusion |
| `ad_populum` | Argumentum ad populum; appeal to common belief as evidence | ad populum | *alle sagen, die Mehrheit denkt, jeder weiß, niemand zweifelt daran* | Universal quantifier + belief verb without evidence |
| `straw_man` | Pragma-dialectics standpoint rule violation — misrepresenting opponent's position | straw man | Opponent position restated + attacked; *ihr behauptet also, du meinst wohl* before distorted restatement | Restatement marker + opponent reference + attack |
| `tu_quoque` | Whataboutism; tu quoque — deflecting criticism via counter-accusation | tu quoque | *was ist mit X, ihr habt doch auch, ihr macht doch dasselbe* | Counter-accusation deflection structure |
| `bifurcation` | False dichotomy / bifurcation — artificially restricting option space | bifurcation | *entweder...oder* without acknowledged middle ground; *es gibt nur zwei Möglichkeiten* | Binary disjunction marker without concession |
| `absolutism` | Appraisal graduation — *force* (intensity) and *focus* (black-and-white); cognitive distortion (Beck) | — | *immer, nie, alle, keiner, vollkommen, absolut, eindeutig, auf keinen Fall* | Absolutism lemma list; frequency in sentence |
| `strategic_manoeuvring` | Van Eemeren strategic manoeuvring; motivated reasoning (Kunda) — arguing toward own interest | special pleading | 1st-person benefit framing without grounds; selectively applied standards; *für uns wäre das gut weil* | 1st-person + benefit NP without warrant |
| `gaslighting` | DARVO pattern (Deny, Attack, Reverse Victim and Offender); epistemic injustice (Fricker) | — | *das hast du dir eingebildet, das war nie so, du übertreibst, du bist zu sensibel* | Reality-negation + 2nd-person perception target |
| `incoherence` | Pragma-dialectics consistency rule violation; performative contradiction (Habermas) | — | Flagged cross-statement — not scored per-sentence; see §6 |

#### Emotion vector (per sentence)

From SentiWS + NRC-German lexicon, attached to every record regardless of primary label:

```
valence:     float  -1.0 .. +1.0   (SentiWS)
arousal:     float   0.0 ..  1.0   (derived from intensity weight)
anger:       float   0.0 ..  1.0   (NRC Plutchik)
fear:        float   0.0 ..  1.0
disgust:     float   0.0 ..  1.0
sadness:     float   0.0 ..  1.0
joy:         float   0.0 ..  1.0
trust:       float   0.0 ..  1.0
anticipation: float  0.0 ..  1.0
surprise:    float   0.0 ..  1.0
```

---

## 4. Input Sources

### 4.1 External Corpus

Text files with attribution metadata. Accepted formats:

| Format | Structure |
|---|---|
| Plain text | `[SpeakerName]: sentence or paragraph text` one per line |
| JSON | `[{"speaker":"X","topic":"/path","text":"...","ts":"ISO8601"}]` |
| CSV | `speaker,topic,text,ts` |

`topic` is a free string that should map to an InfoPedia path (e.g. `/politik/klima`). Unmapped topics are stored as-is; a `--topic-map topics.json` file can alias external labels to InfoPedia paths.

### 4.2 InfoPedia Entries

Via HTTP `GET /entries?format=json` or directly from the CSV cache file. Entry content field → text; entry path → topic; `tid` + session → speaker identifier.

### 4.3 Session Protocol (v2 — stub only)

Frontend records typed/submitted statements for a specific `tid` + topic combination. Input format identical to §4.1 JSON. Not implemented in v1; input reader stub defined so the pipeline can accept it when ready.

---

## 5. Output Format

One JSONL record per analysed sentence, appended to `data/analysis.jsonl`.

```json
{
  "id":        "sha1(speaker + topic + sentence)",
  "ts":        "2026-06-27T10:00:00Z",
  "speaker":   "known_name_or_tid",
  "topic":     "/politik/klima",
  "source":    "infopedia|external|session",
  "sentence":  "Ihr habt das immer so gemacht.",
  "svo": {
    "subject_lemma":  "ihr",
    "verb_lemma":     "machen",
    "verb_tense":     "perfekt",
    "verb_modality":  null,
    "object_lemma":   "das",
    "negated":        false,
    "speaker_role":   "2nd_pl"
  },
  "dimensions": {
    "logos":                 0.0,
    "ethos_legitimate":      0.0,
    "pathos_empathic":       0.0,
    "engagement_open":       0.0,
    "pathos_manipulative":   0.05,
    "blame_attribution":     0.75,
    "ad_hominem":            0.2,
    "ad_ignorantiam":        0.0,
    "ad_populum":            0.0,
    "straw_man":             0.0,
    "tu_quoque":             0.0,
    "bifurcation":           0.0,
    "absolutism":            0.80,
    "strategic_manoeuvring": 0.0,
    "gaslighting":           0.0
  },
  "primary_label":    "absolutism",
  "secondary_label":  "blame_attribution",
  "emotion": {
    "valence":      -0.4,
    "arousal":       0.7,
    "anger":         0.6,
    "fear":          0.1,
    "disgust":       0.3,
    "sadness":       0.0,
    "joy":           0.0,
    "trust":         0.0,
    "anticipation":  0.1,
    "surprise":      0.0
  },
  "contradiction_flag":  false,
  "contradiction_with":  null
}
```

---

## 6. Contradiction Detection

Cross-statement comparison within a `(topic × verb_lemma)` group.

**Algorithm:**
1. Group all records by `(topic, verb_lemma)`
2. Within each group, compare `(subject_lemma, object_lemma, negated)` triples pairwise
3. Flag as **intra-speaker temporal contradiction**: same speaker, same subject+verb+object, `negated` differs across time
4. Flag as **inter-speaker contradiction**: different speakers, same subject+verb+object, `negated` differs — or same subject+verb but opposite-polarity objects (SentiWS valence sign flip on object head)
5. Flag as **performative contradiction** (Habermas): speaker's primary rhetorical label (e.g. `engagement_open`) contradicts the fallacy present in the same sentence (e.g. `ad_hominem`)
6. Write `contradiction_flag: true` + `contradiction_with: ["<id>", ...]` on all implicated records

---

## 7. Tool Architecture

**Primary implementation: Python (Hypothesis A)**

```
lang_analyze/
  __main__.py           # CLI: --source, --input, --output, --topic-map, --append
  pipeline.py           # orchestration: read → segment → svo → rhetoric → emotion → write
  svo.py                # spaCy UD dependency tree → SVO triple + metadata
  rhetoric.py           # lexicon + rule-based dimension scoring (14 dimensions)
  emotion.py            # SentiWS + NRC-German scoring → emotion vector
  contradiction.py      # cross-record group comparison → flag injection
  readers/
    infopedia.py        # GET /entries JSON or parse CSV cache
    file_reader.py      # plain text / JSON / CSV external corpus
    session_stub.py     # v2 placeholder
  data/
    sentiws/            # SentiWS_v1.8c_153165_NEG.xml + POS.xml (Leipzig, free)
    nrc_german.tsv      # NRC Emotion Lexicon German translation (Mohammad et al., free)
    rules/              # YAML rule definitions per rhetorical dimension
      blame.yaml
      fear_appeal.yaml
      absolutism.yaml
      ...
```

**Python dependencies:**
- `spacy` + `de_core_news_md` (~45 MB, MIT/CC-BY-SA) — POS tagging, lemmatisation, UD dependency parse
- `pyyaml` — rule definitions
- Standard library only otherwise (no Composer equivalent bloat)

**Running:**
```bash
# Analyse InfoPedia entries
python -m lang_analyze \
  --source infopedia \
  --input http://localhost/entries \
  --topic-map config/topics.json \
  --output data/analysis.jsonl

# Analyse external corpus, append to existing output
python -m lang_analyze \
  --source external \
  --input corpus/protokoll_2026-06.json \
  --output data/analysis.jsonl \
  --append
```

---

## 8. Integration with InfoPedia

**`data.php`** gains a new entity `analysis`:
- Reads `data/analysis.jsonl` (same incremental pattern as stats/ops)
- Supports filter params: `f[speaker]`, `f[topic]`, `f[label]`, `f[source]`
- Response shape: same `{entity, offset, full, increments}` structure as existing entities

**Visualisation (v2):**
- Dimension distribution per topic (stacked bar or heatmap by speaker)
- Contradiction flags surfaced as highlighted pairs
- Timeline of primary labels per speaker on a topic
- Rhetoric quality score per speaker (ratio constructive : manipulative)

---

## 9. Alternatives

**B — Stanza** (Apache 2.0, Stanford NLP):
Better German accuracy on complex syntax; same UD output labels; drop-in replacement for `svo.py`. Slower startup (~1–2 s model load); heavier (~400 MB). Preferred if spaCy `md` model proves insufficient for the corpus at hand. All downstream code unchanged.

**C — UDPipe binary** (MPL 2.0):
No Python required; called from PHP `exec()`; outputs CoNLL-U. Stays entirely within the PHP stack. Trades Python ecosystem richness for deployment simplicity. Preferred if Python on the target host is a hard constraint. Requires CoNLL-U parser in PHP (~50 LOC).

---

## 10. v1 Scope

**In:**
- SVO extraction for all tenses and constructions in §3.1
- All 15 rhetorical dimensions scored rule-based (14 listed + `incoherence` via contradiction module)
- Emotion vector via SentiWS + NRC-German
- External corpus input (plain text, JSON, CSV)
- InfoPedia entries input (HTTP or cache file)
- JSONL output + `data.php` `analysis` entity with filter support
- Intra-speaker temporal + inter-speaker + performative contradiction detection

**Out (v2+):**
- Session-protocol frontend (recording UI for specific tid + topic)
- ML-based dimension classifier replacing rule-based scoring
- Per-sentence confidence calibration
- Cross-language extension
- Real-time analysis pipeline
- Rhetoric quality dashboard in `statistic.html`
