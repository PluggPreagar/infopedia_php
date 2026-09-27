# „Früher war alles besser?“ – Methodik

Input für fayf.info · Datensatz `frueher_besser_indikatoren.csv` · Stand: 27.09.2026

## 1. Ziel

Das Projekt stellt die These „früher war alles besser“ faktenbasiert auf den Prüfstand. Es vergleicht messbare Indikatoren über mehrere Epochen hinweg und bezieht sie wo möglich auf das Individuum, also auf Lebenszeit, Arbeitszeit und Risiko. Die Erwartung lautet: Das Gesamtbild ist positiv, aber nicht linear. Einzelne Epochen waren bei einzelnen Indikatoren besser, und diese Fälle werden offen gezeigt.

## 2. Scope

| Dimension | Inhalt |
|---|---|
| Raum | Deutschland (Deutsches Reich / BRD / DE). Weicht der Raum ab (Europa, Hamburg, Jäger und Sammler), steht das in `anmerkung`. |
| Epochen | Steinzeit, Mittelalter, Spätmittelalter, Frühe Neuzeit, 1800, 1850, 1900, 1950, 2000, heute |
| Kategorien | Gesundheit, Medizin / Todesursachen, Pandemien, Arbeit, Ernährung, Alltag, Umwelt, Gewalt, Krieg |
| Bezug | bevorzugt je Kopf, je 100.000, je Arbeitsminute oder je Mann bzw. Kind |
| Umfang | 185 Zeilen, 48 Indikatoren |

## 3. Non-Scope

| Ausgeschlossen | Grund |
|---|---|
| Subjektive Maße (Glück, Zufriedenheit, Sinn) | nicht epochenübergreifend messbar |
| Politische Bewertung und Handlungsempfehlungen | Ziel ist Faktenbasis, keine Meinung |
| Globale Vergleiche außer als Kontext | Fokus auf Deutschland |
| Kosten des Fortschritts im Detail (Klima, Biodiversität, Adipositas) | nur als Anmerkung, kein eigener Indikatorblock |
| Einzelwerte ohne Spanne vor 1870 | Scheingenauigkeit |
| Urlaub und Arbeitszeit als Linie Steinzeit → heute | Konzept nicht vergleichbar, nur 1850/1900 → heute belastbar |

## 4. Vorgehen

1. **Indikatoren sammeln**: Start mit Kernfakten (Pocken, Polio, Säuglingssterblichkeit, Arbeitszeit, Anteil Landwirtschaft). Danach Erweiterung um Ernährung, Krieg, Todesursachen und Pandemien.
2. **Epochen prüfen**: Für jeden Indikator klären, ob Werte für Steinzeit und Mittelalter belastbar sind oder nur ab 1870.
3. **Spannen statt Einzelwerte**: `wert_min` / `wert_max` für jede Zeile.
4. **Quellen belegen**: Zeilengenaue URL, Erstnennung suchen, `url_status` vergeben.
5. **Werte korrigieren**: Wenn die Quellenprüfung einen Wert widerlegt, wird er angepasst und in `anmerkung` dokumentiert (z. B. Verkehrstote 1970, Wohnfläche 1956, Fleisch Spätmittelalter).
6. **Plausibilitätschecks**: Fragwürdige Werte werden gegengerechnet, z. B. Fleischkonsum über die Arbeitsminuten je kg.

## 5. Kriterien

### 5.1 Aufnahme eines Indikators
- Er ist über mindestens 2 Epochen messbar.
- Eine Richtung ist interpretierbar (besser / schlechter).
- Er hat Bezug zum Alltag oder zur Lebenszeit des Individuums.
- Eine Quelle ist benennbar. Sonst lautet der Status `Quelle ergänzen`.

### 5.2 Datenqualität

| Wert | Bedeutung |
|---|---|
| Statistik | amtliche Zählung (ab ca. 1871) |
| Schätzung | aus Kirchenbüchern, Steuerlisten, Ernteregistern, Sekundärliteratur |
| Schätzung (berechnet) | eigene Berechnung aus Lohn und Preis der Quelle, Annahmen stehen in `anmerkung` |
| Rekonstruktion | aus Skeletten, Archäologie, Ethnografie |

### 5.3 Nicht-Linearität (`frueher_besser`)

| Wert | Bedeutung |
|---|---|
| ja | Wert besser als in einer späteren Epoche |
| teilweise | nur gegenüber einzelnen Epochen besser |
| nein | sonst |

Aktuell sind 7× `ja`, 7× `teilweise` und 1× `ja (roh)` markiert. Beispiele: Körpergröße im Mittelalter, Kalorienangebot und Fleischkonsum im Spätmittelalter, Brot-Arbeitsminuten im Spätmittelalter, Wochenarbeitszeit vor 1850.

### 5.4 Quellen

| `url_status` | Bedeutung |
|---|---|
| verifiziert | exakte Publikation oder Tabelle per Suche geprüft |
| sekundär | Presse, Wikipedia, Aggregator, nicht das Original |
| sekundär (Paywall) | Wert nur über Snippet sichtbar |
| Portal (nicht zeilengenau) | Datenbank-Startseite, die Tabelle ist noch zu suchen |
| ungeprüft (URL-Muster) | URL konstruiert, nicht geprüft |
| Gesetz | Gesetzestext |
| keine URL | Buch ohne Online-Fassung |

In `erstnennung` steht `ja` (die URL ist die Erstnennung), `nein: <Erstnennung>`, `unklar` oder `Quelle ergänzen`.

### 5.5 Spannen
- Die Spanne bildet Unsicherheit, Regionalität und Definitionsunterschiede ab.
- Bei widersprüchlichen Quellen enthält die Spanne beide Werte, und `anmerkung` nennt beide Quellen (z. B. Fleisch 1950: 14–40 kg).

### 5.6 Kennzeichnung `PRÜFEN`
Die Markierung `PRÜFEN` in `anmerkung` bedeutet: Der Wert ist unsicher und vor der Publikation zu verifizieren. Aktuell betrifft das 16 Zeilen.

## 6. Datenmodell (CSV)

Trennzeichen `;`, Dezimalpunkt `.`, UTF-8.

| Spalte | Inhalt |
|---|---|
| id | laufende Nummer |
| kategorie | siehe Scope |
| indikator | Name, einheitliche Begriffe (Glossar) |
| einheit | z. B. `% im 1. Lebensjahr`, `je 100.000`, `min` |
| epoche | siehe Scope |
| jahr | Einzeljahr oder Bereich `von..bis` |
| wert_min / wert_max | Spanne |
| datenqualitaet | siehe 5.2 |
| frueher_besser | siehe 5.3 |
| anmerkung | Kontext, Annahmen, `PRÜFEN` |
| quelle | Kurzname |
| quelle_url | URL(s), mehrere mit ` \| ` getrennt |
| url_status | siehe 5.4 |
| erstnennung | siehe 5.4 |

## 7. Bekannte Grenzen

- **Rohe Raten**: Krebs- und Herz-Kreislauf-Todesfälle sind nicht altersbereinigt. Ein Anstieg zeigt deshalb auch, dass Menschen länger leben.
- **OWID-Kindersterblichkeit**: misst Tod unter 5 Jahren, der Datensatz sonst Tod unter 15 Jahren.
- **Mittelalterliche Löhne**: teils mit Kost bezahlt. Die berechneten Arbeitsminuten sind dort eher überschätzt.
- **Museumsangaben** (Historisches Museum Frankfurt): Die Primärquelle dahinter ist unklar.
- **Gebietsstand**: wechselt zwischen Reich, BRD und DE. Vermerk in `anmerkung`.
- **Portal-Zeilen**: 36 Zeilen haben noch keine zeilengenaue URL.

## 8. Offene Punkte

- Portal-Zeilen auflösen: Tötungsrate, Krieg (Waisen, Witwen, Wehrdienst), Kalorien 1950
- Altersbereinigte Todesursachen-Raten ergänzen
- Autor und Region der Lohn-Getreide-Tabelle (kpbc) klären
- Meschede-Pocken: Datierung 1970 vs. 1961/62 klären
- Zeilen mit „heute = 0“: Status `trivial (0)` einführen

## 9. Glossar

| Begriff | Definition |
|---|---|
| Säuglingssterblichkeit | Gestorbene im 1. Lebensjahr, in % der Lebendgeborenen |
| Kindersterblichkeit | Gestorbene vor dem 15. Lebensjahr, in % |
| Müttersterblichkeit | Todesfälle durch Schwangerschaft oder Geburt je 100.000 Lebendgeborene |
| Lebenserwartung | bei Geburt, in Jahren (Spanne M..F) |
| Lebenserwartung ab 15 | erreichtes Alter, wenn 15 Jahre erreicht wurden |
| Anteil Landwirtschaft | in der Landwirtschaft Erwerbstätige, in % |
| Kalorienangebot | verfügbare Nahrungsenergie je Kopf und Tag inkl. Verlusten, nicht Verzehr |
| Hungerjahr | Jahr mit regionaler Hungerkrise und erhöhter Sterblichkeit |
| Hungermonate | saisonaler Nahrungsmangel pro Jahr |
| Kirchliche Fastentage | religiöser Fleischverzicht, kein Kalorienmangel |
| Arbeitsminuten | Arbeitszeit, die für den Kauf von 1 Einheit nötig ist |
| rohe Rate | Tote je 100.000, nicht altersbereinigt |
| Grundleiden | ausschlaggebende Todesursache laut Totenschein |
| Übersterblichkeit | Sterbefälle über dem erwarteten Wert |
| Statistik / Schätzung / Rekonstruktion | siehe 5.2 |
