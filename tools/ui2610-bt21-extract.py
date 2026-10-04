#!/usr/bin/env python3
"""UI2610: candidate passages from the 21st Bundestag (Open Discourse) per leaf topic.

Usage: python3 tools/ui2610-bt21-extract.py <open-discourse/python/data> <out-dir> [per_leaf=40]
Input : 02_cached/electoral_term_19_20/stage_03/electoral_term_21/speech_content/speech_content.pkl
        03_final/factions.pkl
Output: <out-dir>/<leaf path with __>.json — [{speech_id, session, date, speaker, role, text, score, hits}]
Pure scoring in score_passage() / passages(); I/O only in main().
"""
import json
import os
import re
import sys
from datetime import datetime, timezone

# Leaf topic → keyword regexes (lower case, German). Hits are counted per distinct pattern.
KEYWORDS = {
    "wirtschaft/inflation": [r"inflation", r"lebenshaltungskosten", r"preissteigerung", r"teuerung", r"kaufkraft", r"lebensmittelpreis"],
    "wirtschaft/standort/industrie": [r"industrie", r"deindustrialisierung", r"arbeitsplätze in der industrie", r"autoindustrie", r"stahl", r"chemieindustrie", r"abwanderung"],
    "wirtschaft/standort/buerokratie": [r"bürokratie", r"bürokratieabbau", r"berichtspflicht", r"genehmigungsverfahren", r"planungsbeschleunigung", r"lieferkettengesetz"],
    "wirtschaft/standort/energiepreise": [r"energiepreis", r"strompreis", r"gaspreis", r"stromsteuer", r"netzentgelt", r"industriestrompreis"],
    "wirtschaft/haushalt/schuldenbremse": [r"schuldenbremse", r"sondervermögen", r"neuverschuldung", r"kreditaufnahme", r"schulden"],
    "wirtschaft/haushalt/kuerzungen": [r"haushaltsloch", r"kürzung", r"einsparung", r"sparpaket", r"steuererhöhung", r"konsolidierung", r"haushaltslücke"],
    "wirtschaft/arbeitsmarkt": [r"arbeitslos", r"fachkräftemangel", r"arbeitsmarkt", r"beschäftigung", r"mindestlohn", r"arbeitszeit"],
    "soziales/armut": [r"armut", r"kinderarmut", r"ungleichheit", r"existenzminimum", r"tafeln", r"verteilungsgerechtigkeit"],
    "soziales/rente/rentenniveau": [r"rentenniveau", r"haltelinie", r"rentenpaket", r"rentenhöhe", r"altersarmut"],
    "soziales/rente/generationen": [r"generationengerecht", r"beitragssatz", r"junge generation", r"aktivrente", r"frühstart-rente", r"renteneintrittsalter", r"lebensarbeitszeit"],
    "soziales/pflege": [r"pflege", r"pflegeversicherung", r"eigenanteil", r"pflegekräfte", r"pflegeheim", r"pflegende angehörige"],
    "soziales/grundsicherung": [r"bürgergeld", r"grundsicherung", r"sanktion", r"totalverweigerer", r"regelsatz", r"jobcenter"],
    "soziales/wohnen": [r"mietpreisbremse", r"wohnungsbau", r"miete", r"wohnungsnot", r"bauturbo", r"sozialwohnung"],
    "soziales/demografie": [r"demografi", r"alternde gesellschaft", r"geburtenrate", r"babyboomer", r"bevölkerungsentwicklung"],
    "gesundheit/versorgung": [r"krankenhausreform", r"krankenhaus", r"klinik", r"ärztemangel", r"notaufnahme", r"landarzt", r"facharzttermin", r"primärarzt"],
    "gesundheit/beitraege": [r"zusatzbeitrag", r"krankenkassenbeitrag", r"krankenversicherung", r"gkv", r"beitragserhöhung", r"kassenbeitr"],
    "migration/asyl/begrenzung": [r"zurückweisung", r"grenzkontrolle", r"begrenzung der migration", r"asylbewerber", r"familiennachzug", r"abschiebung", r"irreguläre migration"],
    "migration/asyl/eu-reform": [r"geas", r"gemeinsames europäisches asylsystem", r"asylreform", r"dublin", r"außengrenze", r"drittstaat"],
    "migration/integration": [r"integration", r"integrationskurs", r"sprachkurs", r"einbürgerung", r"parallelgesellschaft", r"beschäftigung von geflüchteten"],
    "migration/fachkraefte": [r"fachkräfteeinwanderung", r"einwanderung in den arbeitsmarkt", r"anerkennung von berufsabschlüssen", r"work-and-stay", r"blue card", r"arbeitsmigration"],
    "sicherheit/kriminalitaet": [r"kriminalität", r"messerangriff", r"gewaltkriminalität", r"kriminalstatistik", r"clankriminalität", r"innere sicherheit"],
    "sicherheit/terror": [r"terror", r"anschlag", r"islamis", r"extremis", r"gefährder", r"verfassungsschutz"],
    "sicherheit/verteidigung/bundeswehr": [r"bundeswehr", r"verteidigungsausgaben", r"aufrüstung", r"kriegstüchtig", r"beschaffung", r"nato-ziel", r"fünf prozent"],
    "sicherheit/verteidigung/wehrdienst": [r"wehrdienst", r"wehrpflicht", r"musterung", r"freiwillige", r"kriegsdienstverweiger", r"dienstpflicht"],
    "klima/klimaschutz": [r"klimaschutz", r"klimaziel", r"klimaneutral", r"co2", r"klimawandel", r"klimaanpassung", r"hitzeschutz"],
    "klima/energiewende": [r"energiewende", r"erneuerbare", r"windkraft", r"photovoltaik", r"heizungsgesetz", r"gebäudeenergiegesetz", r"netzausbau", r"gaskraftwerk"],
    "klima/verkehr/bahn": [r"deutsche bahn", r"schiene", r"deutschlandticket", r"pünktlichkeit", r"bahnstrecke", r"sanierung der bahn"],
    "klima/verkehr/auto": [r"verbrenner", r"elektroauto", r"e-auto", r"autobahn", r"tempolimit", r"pendlerpauschale", r"kfz"],
    "klima/landwirtschaft": [r"landwirt", r"agrardiesel", r"bauern", r"tierhaltung", r"pflanzenschutz", r"naturschutz", r"wolf"],
    "bildung/schule/lehrermangel": [r"lehrermangel", r"lehrkräfte", r"lehrerinnen und lehrer", r"unterrichtsausfall", r"quereinsteiger"],
    "bildung/schule/pisa": [r"pisa", r"bildungsniveau", r"grundschule", r"lesekompetenz", r"bildungsgerechtigkeit", r"startchancen"],
    "bildung/ausbildung": [r"ausbildung", r"azubi", r"berufsausbildung", r"hochschule", r"studium", r"bafög"],
    "bildung/digitalisierung": [r"digitalisierung", r"künstliche intelligenz", r"\bki\b", r"digitalpakt", r"verwaltungsdigitalisierung", r"breitband"],
    "demokratie/vertrauen": [r"vertrauen in die demokratie", r"politikverdrossenheit", r"handlungsfähig", r"vertrauen der bürger", r"politikwechsel"],
    "demokratie/polarisierung": [r"spaltung", r"polarisierung", r"rechtsextrem", r"linksextrem", r"brandmauer", r"verbotsverfahren", r"hetze"],
    "demokratie/medien": [r"desinformation", r"fake news", r"öffentlich-rechtlich", r"rundfunk", r"meinungsfreiheit", r"plattformen", r"social media"],
    "demokratie/gleichstellung": [r"gleichstellung", r"parität", r"frauenanteil", r"lohnlücke", r"gender", r"gewalt gegen frauen", r"selbstbestimmung"],
    "welt/ukraine": [r"ukraine", r"russland", r"putin", r"waffenstillstand", r"taurus", r"waffenlieferung"],
    "welt/handel": [r"zölle", r"zoll", r"freihandel", r"mercosur", r"handelsabkommen", r"exportwirtschaft", r"trump"],
    "welt/nahost": [r"israel", r"gaza", r"hamas", r"nahost", r"iran", r"antisemitismus"],
}

FACTIONS = {0: "AfD", 4: "Grüne", 5: "CDU/CSU", 7: "Linke", 25: "SPD", 18: "fraktionslos", 3: "BSW"}


def passages(text, max_len=1400):
    """Split a speech into windows of consecutive paragraphs, each ≤ max_len chars."""
    paras = [p.strip() for p in re.split(r"\n\s*\n", text or "") if len(p.strip()) > 40]
    out, cur = [], ""
    for p in paras:
        if cur and len(cur) + len(p) + 1 > max_len:
            out.append(cur)
            cur = ""
        cur = (cur + "\n" + p).strip() if cur else p[:max_len]
    if cur:
        out.append(cur)
    return out


def score_passage(text, patterns):
    """(distinct pattern hits, matched patterns). Lower-cased regex search."""
    low = text.lower()
    hits = [p for p in patterns if re.search(p, low)]
    return len(hits), hits


def pick(cands, per_leaf, per_faction=8, per_speech=1):
    """Best-first with diversity caps: ≤ per_faction per speaker group, ≤ per_speech per speech."""
    cands = sorted(cands, key=lambda c: (-c["score"], -len(c["text"])))
    out, by_f, by_s = [], {}, {}
    for c in cands:
        f, s = c["role"], c["speech_id"]
        if by_f.get(f, 0) >= per_faction or by_s.get(s, 0) >= per_speech:
            continue
        out.append(c)
        by_f[f] = by_f.get(f, 0) + 1
        by_s[s] = by_s.get(s, 0) + 1
        if len(out) >= per_leaf:
            break
    return out


def main():
    import pandas as pd  # only for I/O
    data_dir, out_dir = sys.argv[1], sys.argv[2]
    per_leaf = int(sys.argv[3]) if len(sys.argv) > 3 else 40
    d = pd.read_pickle(os.path.join(data_dir, "02_cached/electoral_term_19_20/stage_03/electoral_term_21/speech_content/speech_content.pkl"))
    d = d[(d.position_short != "Presidium of Parliament") & (d.speech_content.str.len() > 600)]
    os.makedirs(out_dir, exist_ok=True)
    cands = {leaf: [] for leaf in KEYWORDS}
    for row in d.itertuples(index=False):
        if row.faction_id in FACTIONS:
            role = FACTIONS[row.faction_id]
        else:
            role = (row.position_long or row.position_short or "").strip() or "ohne Fraktion"
        meta = {
            "speech_id": int(row.id), "session": str(row.session),
            "date": datetime.fromtimestamp(float(row.date), tz=timezone.utc).strftime("%Y-%m-%d"),
            "speaker": f"{row.first_name} {row.last_name}".strip(), "role": role,
        }
        for ptxt in passages(row.speech_content):
            for leaf, pats in KEYWORDS.items():
                n, hits = score_passage(ptxt, pats)
                if n >= 2 or (n == 1 and len(pats) <= 5):
                    cands[leaf].append({**meta, "text": ptxt, "score": n, "hits": hits})
    for leaf, cs in cands.items():
        sel = pick(cs, per_leaf)
        with open(os.path.join(out_dir, leaf.replace("/", "__") + ".json"), "w", encoding="utf-8") as fh:
            json.dump(sel, fh, ensure_ascii=False, indent=0)
        print(f"{leaf:40} candidates {len(cs):6}  picked {len(sel)}")


if __name__ == "__main__":
    main()
