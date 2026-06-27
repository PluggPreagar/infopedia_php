# German Discourse Analysis — Design Spec

**Date:** 2026-06-27
**Branch:** `feature/german-lang-analysis`
**Affects:** new standalone Python tool · `data.php` (new `analysis` entity) · optional future `statistic.html` extension

---

## 0. Context

### Why discourse analysis for a knowledge base

InfoPedia stores structured knowledge contributed by identified speakers — tenants with known identities — on a hierarchical topic tree (paths like `/politik/klima`, `/gesellschaft/migration`). When multiple speakers contribute entries on the same topic over time, their entries form a **discourse corpus**: interconnected claims, rebuttals, and framings on a shared subject.

The quality of that discourse determines whether the knowledge base grows richer or degrades into noise. Two entries on the same topic may reach opposite conclusions — but *how* they argue matters as much as *what* they conclude. A speaker citing peer-reviewed evidence differs fundamentally from one who amplifies fear, deflects blame, or misrepresents the opposing position.

### The known-speakers model

Unlike anonymous public discourse analysis, InfoPedia operates with **known tenants**. Each entry is attributed to a `tid` (tenant identifier). This enables:

- **Intra-speaker consistency** — does a speaker's claim on topic X contradict their earlier statement on the same verb/object cluster?
- **Inter-speaker comparison** — given three speakers on `/politik/klima`, who argues constructively and who relies on fallacy patterns?
- **Topic-level quality** — what is the constructive-to-manipulative ratio for a topic across all contributors?

**Concrete example:**

| Speaker | Statement | Expected primary label |
|---------|-----------|------------------------|
| Anna | *„Laut IPCC-Daten stieg die Temperatur um 1,1 °C seit 1850 – das zeigt Handlungsbedarf."* | `logos` |
| Ben | *„Wenn wir das nicht sofort stoppen, verlieren wir alles – Küsten, Ernten, Zukunft."* | `pathos_manipulative` (ad metum) |
| Carl | *„Ihr wollt ja nur die Wirtschaft kaputt machen – das sagen alle hier."* | `straw_man` + `ad_populum` |

The tool extracts these classifications automatically from the attributed text and feeds the results into the InfoPedia data channel for visualisation and speaker comparison.

### Why deterministic rules, not machine learning

Argument mining research increasingly relies on neural classifiers (BERT, RoBERTa fine-tuned on German). This tool deliberately chooses rule-based detection for v1:

| Criterion | ML classifier | Rule-based (this tool) |
|-----------|--------------|------------------------|
| Explainability | Black-box output | Every score traceable to a rule or lexicon hit |
| Training data | Requires a labelled German corpus | No training data needed |
| Speed | 100–500 ms/sentence (GPU) | ~5 ms/sentence (CPU) |
| Auditability | Hard — why did it fire? | Easy — which YAML rule matched? |
| Stability | Can drift with model updates | Deterministic given the same lexicon version |
| Domain correction | Requires re-training | Edit a YAML rule file |

The YAML rule files in `lang_analyze/data/rules/` are the auditable artefact: a domain expert can read `blame.yaml`, dispute a surface marker, and correct it without touching Python code.

### What "helpful vs. manipulative" means concretely

The tool classifies *argumentative strategy*, not *truth value*. A statement can be factually correct and still score high on `pathos_manipulative` (if it forecloses alternatives via fear). A statement can be factually wrong and score high on `logos` (if it cites a source, even a flawed one).

| Category | Characterisation | Dimensions |
|----------|-----------------|------------|
| **Constructive** | Advances shared understanding; acknowledges uncertainty; supports or refutes claims with evidence or empathy | `logos` · `ethos_legitimate` · `pathos_empathic` · `engagement_open` |
| **Manipulative / fallacious** | Advances the speaker's position by exploiting cognitive shortcuts, social pressure, or emotional coercion | `pathos_manipulative` · `blame_attribution` · `ad_hominem` · `ad_ignorantiam` · `ad_populum` · `straw_man` · `tu_quoque` · `bifurcation` · `gaslighting` |
| **Stylistic risk markers** | Not inherently bad; elevated scores contextually significant | `absolutism` · `strategic_manoeuvring` |

Contradiction detection then surfaces **performative contradictions** (Habermas): a speaker who claims `engagement_open` while simultaneously deploying `ad_hominem` is contradicting their own stated discursive position in the same breath.

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

**Verb construction resolution — decision tree:**

```
                 spaCy dependency parse of one clause
                               │
                               ▼
                      Find ROOT token
                               │
          ┌────────────────────┴─────────────────────┐
          │                                          │
  dep="aux:pass" child present?              no aux:pass
          │                                          │
         yes                               werden as plain aux?
          │                                          │
          ▼                                 ┌────────┴────────┐
     passiv = true                         yes               no
     verb  = ROOT.lemma                     │                 │
     tense = passiv                         ▼                 ▼
                                        Futur I       haben/sein as aux
                                  verb = xcomp.lemma  with ROOT=participle?
                                  tense = futur              │
                                                    ┌────────┴────────┐
                                                   yes               no
                                                    │                 │
                                                    ▼                 ▼
                                                Perfekt         modal as ROOT
                                          verb = ROOT.lemma   with xcomp present?
                                          tense = perfekt            │
                                                           ┌─────────┴─────────┐
                                                          yes                  no
                                                           │                   │
                                                           ▼                   ▼
                                                        Modal         Präsens/Präteritum
                                                 verb = xcomp.lemma  verb = ROOT.lemma
                                                 modality = ROOT     tense from ROOT.morph
```

**Post-processing:** if ROOT has a `compound:prt` child, prefix the particle:
`verb_lemma = prt.lower_ + ROOT.lemma_`  →  `"an"` + `"rufen"` = `"anrufen"`

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

Position shift records (§6a) are also written to `data/analysis.jsonl` with `"type": "shift"` and a distinct schema — see §6a for the full field list.

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

**Contradiction detection — algorithm flow:**

```
 All JSONL records
        │
        ▼
┌───────────────────────────────┐
│  Group by (topic, verb_lemma) │
└───────────────┬───────────────┘
                │
    for each group G, pairwise compare (A, B):
                │
        ┌───────┴──────────────────────────────────┐
        │                                          │
  same_speaker(A, B)?                      diff_speaker(A, B)?
        │                                          │
       yes                                        yes
        │                                          │
  negated(A) ≠ negated(B)?           negated(A) ≠ negated(B)?
        │                                          │
    ┌───┴───┐                                  ┌───┴───┐
   yes      no                               yes       no
    │                                         │         │
    ▼                                         ▼         ▼
⚑ intra-speaker                  ⚑ inter-speaker    valence sign
  temporal contradiction           contradiction     flip on obj?
                                                          │
                                                      ┌───┴───┐
                                                     yes       no
                                                      │
                                                      ▼
                                          ⚑ inter-speaker (valence-flip)
        │
        ▼
 for each record R independently:
        │
  primary_label(R) in constructive set
  AND any manipulative dimension(R) > 0.4?
        │
    ┌───┴───┐
   yes       no
    │
    ▼
⚑ performative contradiction (Habermas)
        │
        ▼
 write contradiction_flag=true
       contradiction_with=[...ids]
 on all implicated records
```

---

## 6a. Diachronic Speaker Consistency

Contradiction detection (§6) flags *that* two records contradict each other. This section asks the **follow-up question**: is the shift *understandable*?

A speaker who revises a position after citing new evidence is different from one who silently reverses course when the topic becomes popular. A single deviant statement surrounded by a consistent record looks different from a sustained pivot. These distinctions matter for evaluating a speaker's overall discourse quality.

### What triggers a position shift

For each `(speaker × topic × verb_lemma)` group, records are sorted by `ts`. A **position shift** is detected when two consecutive records from the same speaker show any of:

- `negated` flips — an assertion becomes a denial (or vice versa) on the same subject+verb
- `object_lemma` valence sign flips — the object head switches from positive to negative (or vice versa) according to SentiWS
- `primary_label` crosses the constructive ↔ manipulative boundary — a speaker who argued with `logos` now predominantly uses `pathos_manipulative`

### Shift classification

Applied to the sentence at the shift point (the later record in the pair). Evaluated in order; first match wins:

| Class | Condition |
|-------|-----------|
| `evidence_responsive` | Shift sentence has `logos > 0.4` AND contains a new-evidence marker (*laut neuen Daten, nach den Erkenntnissen von, neue Studien zeigen, das zeigt jetzt*) |
| `acknowledged` | Shift sentence contains explicit meta-commentary (*ich habe meine Meinung geändert, ich sehe das jetzt anders, angesichts von X ändere ich meinen Standpunkt*) |
| `constructive_growth` | `primary_label` moves from a manipulative dimension to a constructive one; no manipulative markers > 0.3 in the new sentence — speaker is arguing better than before |
| `anomaly` | Single isolated shift, surrounded by ≥ 3 consistent records on each side; treated as a one-off, not a sustained reversal |
| `unexplained` | Shift detected; none of the above markers present — position changed without stated reason |
| `populistic_candidate` | `unexplained` AND the new position aligns with the **modal primary_label** of other speakers on the same topic — moving toward the crowd without explanation |

**Interpretation note:** `populistic_candidate` is a flag for human review, not a verdict. It means the shift was unexplained *and* aligned with the majority direction. It is evidence of potential populism, not proof.

### Output record (`type: shift`)

Shift records are appended to `data/analysis.jsonl` alongside sentence records and carry a distinct `type` field:

```json
{
  "type":        "shift",
  "id":          "sha1(speaker + topic + verb_lemma + ts_from + ts_to)",
  "speaker":     "known_name_or_tid",
  "topic":       "/politik/klima",
  "verb_lemma":  "tun",
  "from_id":     "<sha1 of earlier sentence record>",
  "to_id":       "<sha1 of later sentence record>",
  "ts_from":     "2026-01-15T10:00:00Z",
  "ts_to":       "2026-06-20T14:00:00Z",
  "shift_type":  "negation_flip | object_polarity | label_flip",
  "shift_class": "evidence_responsive | acknowledged | constructive_growth | anomaly | unexplained | populistic_candidate",
  "shift_note":  "primary_label changed from logos to pathos_manipulative"
}
```

`data.php` filter support: `f[type]=shift` · `f[speaker]` · `f[topic]` · `f[shift_class]`

### Algorithm flow

```
 All JSONL sentence records
        │
        ▼
 Group by (speaker × topic × verb_lemma), sort by ts
        │
 for each group, scan consecutive same-speaker pairs (R_earlier, R_later):
        │
  position shift detected?
  (negated flip OR object valence flip OR label boundary cross)
        │
    ┌───┴───┐
   yes       no → skip pair
    │
    ▼
 examine R_later sentence — evaluate in order:
        │
        ├── logos > 0.4 + new-evidence marker? ──────────► evidence_responsive
        │
        ├── explicit acknowledgment marker? ─────────────► acknowledged
        │
        ├── primary_label: manipulative → constructive,
        │   all manipulative dims < 0.3? ────────────────► constructive_growth
        │
        ├── single isolated point (≥ 3 consistent
        │   records on each side)? ──────────────────────► anomaly
        │
        └── no markers → unexplained
            AND new position = modal label of
            other speakers on this topic? ──────────────► populistic_candidate
        │
        ▼
 append type=shift record to analysis.jsonl
```

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
  consistency.py        # per-speaker diachronic position shift detection + classification
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

**Processing pipeline:**

```
┌──────────────────────────────────────────────────────────────────────┐
│  INPUT READERS                                                        │
│  readers/infopedia.py    readers/file_reader.py    session_stub.py   │
│  (HTTP or CSV cache)     (plain text / JSON / CSV)  (v2 — stub)      │
└──────────────────────────────┬───────────────────────────────────────┘
                               │  [{speaker, topic, text, ts}, ...]
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│  SEGMENTATION  (pipeline.py)                                          │
│  spaCy sentence boundary detection → sentence list per record        │
└──────────────────────────────┬───────────────────────────────────────┘
                               │  per sentence
               ┌───────────────┴───────────────┐
               ▼                               ▼
┌──────────────────────────┐   ┌──────────────────────────────────────┐
│  LAYER 1  svo.py          │   │  LAYER 2  rhetoric.py + emotion.py   │
│                           │   │                                       │
│  UD dependency tree walk: │   │  YAML rules per dimension             │
│  nsubj    → subject       │   │  → scored vector 0.0–1.0 (× 15)      │
│  ROOT     → verb resolve  │   │                                       │
│  obj      → object        │   │  SentiWS → valence + arousal          │
│  aux      → tense         │   │  NRC-German → Plutchik 8 emotions     │
│  aux:pass → passiv        │   │                                       │
│  compound:prt → sep. verb │   │  primary_label  (highest score > 0.4) │
│  neg      → negated flag  │   │  secondary_label (2nd score > 0.3)    │
└────────────┬──────────────┘   └─────────────────┬────────────────────┘
             │  SVO triple                         │  dimension + emotion vector
             └───────────────┬─────────────────────┘
                             │  merged record
                             ▼
┌──────────────────────────────────────────────────────────────────────┐
│  CONTRADICTION DETECTION  (contradiction.py)                          │
│  group by (topic × verb_lemma) → pairwise flag injection             │
└──────────────────────────────┬───────────────────────────────────────┘
                               │  finalised records
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│  OUTPUT                                                               │
│  data/analysis.jsonl (append)  →  data.php entity=analysis           │
│                                   filter: f[speaker] f[topic] f[label]│
└──────────────────────────────────────────────────────────────────────┘
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
- Diachronic position shift detection + classification (§6a): evidence_responsive, acknowledged, constructive_growth, anomaly, unexplained, populistic_candidate

**Out (v2+):**
- Session-protocol frontend (recording UI for specific tid + topic)
- ML-based dimension classifier replacing rule-based scoring
- Per-sentence confidence calibration
- Cross-language extension
- Real-time analysis pipeline
- Rhetoric quality dashboard in `statistic.html`

---

## 11. Glossary

Scientific and technical terms used throughout this specification, in alphabetical order.

**Ad hominem** — Fallacy attacking the speaker rather than their argument. Pragma-dialectics: violation of the *freedom rule* (participants must not prevent each other from advancing standpoints). Subforms: abusive (personal insult), circumstantial (bias accusation), tu quoque (whataboutism, see below).

**Ad ignorantiam** *(argumentum ad ignorantiam)* — Exploits knowledge asymmetry: claiming a position is true because the other party cannot disprove it, or excluding interlocutors on grounds of insufficient expertise. Related to Fricker's *testimonial injustice*: deflating a speaker's credibility as a knower based on social category, weaponised to silence.

**Ad metum** *(argumentum ad metum, appeal to fear)* — Compels agreement by invoking extreme negative consequences, bypassing rational evaluation of alternatives. Witte's *Extended Parallel Process Model* (EPPM) describes how disproportionate fear appeals trigger defensive avoidance rather than the intended behaviour change.

**Ad populum** *(argumentum ad populum)* — Treats popular belief as evidence for a claim. Surface markers: universal quantifiers (`alle`, `jeder`, `niemand`) combined with a belief verb, without citing evidence or argument.

**Appraisal Theory** — Linguistic framework (Martin & White, 2005) for analysing how speakers evaluate entities, events, and propositions. Three subsystems: *attitude* (affect, judgement, appreciation), *engagement* (dialogic contraction = shutting down other voices vs. expansion = opening space for them), *graduation* (force/intensity and focus/sharpness of evaluation).

**Argument Mining** — NLP subfield concerned with automatically detecting argumentative content, classifying claims and premises, identifying reasoning patterns, and flagging fallacies in natural language text. Tasks in this tool: claim classification (15 dimensions) and fallacy detection.

**Bifurcation** *(false dichotomy)* — Restricts option space to two alternatives when more exist, forcing a binary choice. German markers: *entweder … oder* without acknowledging a middle ground; *es gibt nur zwei Möglichkeiten*.

**DARVO** — *Deny, Attack, Reverse Victim and Offender* (Freyd, 1997). A response pattern in which the accused denies wrongdoing, attacks the accuser, then repositions themselves as the victim. Operationalised in the `gaslighting` dimension via reality-negation markers targeting the interlocutor's perception (*das hast du dir eingebildet, du übertreibst*).

**Epistemic coercion / epistemic injustice** — Fricker (2007): *testimonial injustice* = deflating a speaker's credibility as a knower based on social identity. *Hermeneutical injustice* = gaps in shared interpretive resources that disadvantage certain speakers. This tool operationalises both via the `ad_ignorantiam` dimension (knowledge-gate framing + 2nd-person exclusion).

**Ethos / Logos / Pathos** — Aristotle's three modes of persuasion. *Ethos*: credibility of the speaker. *Logos*: rational argument, evidence, and valid inference. *Pathos*: emotional appeal. Each has a legitimate variant (genuine expertise, valid reasoning, empathic support) and a manipulative one (false authority = *ad verecundiam*, sophistry, fear appeal = *ad metum*).

**NRC Emotion Lexicon** — Word-emotion association lexicon (Mohammad & Turney, 2013) covering Plutchik's 8 basic emotions and binary sentiment for ~14,000 English words. The German translation used here covers ~10,000 lemmas.

**Performative contradiction** — Habermas (1984): an utterance contradicts itself when its propositional content (e.g. "I am open to dialogue") is falsified by its illocutionary performance (simultaneous deployment of ad hominem). Named *performative* because the contradiction is between what the act does and what it asserts it does.

**Plutchik's Wheel of Emotions** — Model of 8 basic bipolar emotions (Plutchik, 1980): joy–sadness, trust–disgust, fear–anger, anticipation–surprise. Complex emotions arise from pairwise combinations (joy + trust = love; fear + surprise = awe). Structural basis for the NRC Emotion Lexicon dimensions.

**Pragma-dialectics** — Normative theory of argumentation (Van Eemeren & Grootendorst, 1984/2004). Defines the *critical discussion* as the ideal form for resolving differences of opinion. Ten discussion rules — violations constitute the fallacy categories used in §3.2. *Strategic manoeuvring* = pursuing rhetorical goals while maintaining the appearance of reasonable discourse.

**SentiWS** *(Sentiment Wortschatz Deutsch)* — German-language sentiment lexicon (Remus et al., 2010; Leipzig/ASLD). ~3,500 lemmas with POS tags, polarity (positive/negative), and intensity weights (0.0002–1.0). Covers nouns, verbs, adjectives, adverbs.

**Speech Act Theory** — Theory of language use (Austin, 1962; Searle, 1969) in which utterances perform *illocutionary acts*. Five categories: *assertives* (claims), *directives* (commands, demands), *commissives* (promises, threats), *expressives* (apologies, accusations), *declaratives* (pronouncements that change reality by being uttered). Used in this tool to contextualise dimension scoring: a commissive threat raises `pathos_manipulative` more than an assertive warning with equivalent surface markers.

**Strategic manoeuvring** — Van Eemeren & Houtlosser (2002): attempts to gain rhetorical advantage while maintaining the appearance of reasonable discourse. Three aspects: *topical selection* (choose favourable argument terrain), *audience demand adaptation* (exploit shared starting points), *presentational devices* (exploit framing and emphasis).

**Straw man** — Misrepresenting an opponent's position in order to attack the distorted version. Pragma-dialectics: violation of the *standpoint rule* (one may not attribute to the other party a standpoint they have not advanced).

**Toulmin model** — Argument scheme (Toulmin, 1958): *claim* (conclusion) + *grounds* (supporting data) + *warrant* (inference rule linking grounds to claim) + *backing* (support for warrant) + *qualifier* (strength of claim) + *rebuttal* (acknowledged exceptions). The `logos` dimension uses claim + grounds + causal connector as a minimal Toulmin footprint.

**Tu quoque** — "You too" / whataboutism. Deflecting a criticism by pointing to the critic's alleged similar behaviour, without addressing the argument. Pragma-dialectics: violation of the *relevance rule* (one may not advance arguments not relevant to the standpoint under discussion).

**Diachronic analysis** — Analysis across time, tracing how an entity (here: a speaker's stated position) evolves from one point to another. Contrasts with *synchronic* analysis (a single snapshot). In this tool: per-speaker, per-topic tracking of SVO triples and dimension scores sorted by timestamp, enabling position shift detection.

**Populistic candidate** — A detected position shift that is (1) unexplained — the speaker offered no evidence or acknowledgment — and (2) directionally aligned with the majority rhetorical stance of other speakers on the same topic. The label signals that the shift *might* be audience-driven rather than evidence-driven. It is a flag for human review, not a verdict; other explanations (e.g. new private information, social pressure the corpus does not capture) remain possible.

**Universal Dependencies (UD)** — Cross-linguistically consistent syntactic annotation scheme (de Marneffe et al., 2021) used across 100+ languages. Defines ~40 dependency relation labels: `nsubj` (nominal subject), `obj` (direct object), `aux` (auxiliary verb), `aux:pass` (passive auxiliary), `compound:prt` (separable verb particle), `ROOT` (main predicate), `xcomp` (open clausal complement), `neg` (negation modifier). spaCy's `de_core_news_md` outputs UD labels, enabling the SVO resolver to traverse deterministic tree paths regardless of German free word order.
