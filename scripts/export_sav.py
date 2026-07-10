#!/usr/bin/env python3
import json
import os
import sys

import pandas as pd
import pyreadstat

source, destination = sys.argv[1], sys.argv[2]
with open(source, encoding="utf-8") as handle:
    payload = json.load(handle)

columns = list(payload["variables"].keys())
frame = pd.DataFrame(payload["rows"], columns=columns)
labels = {name: metadata[0] for name, metadata in payload["variables"].items()}
for name, metadata in payload["variables"].items():
    if metadata[1] in ("integer", "decimal"):
        frame[name] = pd.to_numeric(frame[name], errors="coerce").astype("float64")
    else:
        frame[name] = frame[name].fillna("").astype("str")
value_labels = {
    **{name: {0: "No", 1: "Sí"} for name in ["uso_prototipo", "transcripcion_audio", "procesamiento_clinico", "generacion_soap"]},
    **{name: {0: "No cumple", 1: "Cumple parcialmente", 2: "Cumple"} for name in ["soap_subjetivo", "soap_objetivo", "soap_evaluacion", "soap_plan", "soap_ubicacion", "soap_claridad"]},
    **{name: {0: "No presenta", 1: "Error leve", 2: "Error moderado", 3: "Error grave"} for name in ["err_transcripcion", "err_omision", "err_agregada", "err_confusion", "err_ubicacion", "err_redaccion"]},
    **{name: {1: "Totalmente en desacuerdo", 2: "En desacuerdo", 3: "Neutral", 4: "De acuerdo", 5: "Totalmente de acuerdo"} for name in [*[f"up{i}" for i in range(1, 7)], *[f"fu{i}" for i in range(1, 7)]]},
}
pyreadstat.write_sav(frame, destination, column_labels=labels, variable_value_labels=value_labels)
verified, metadata = pyreadstat.read_sav(destination)
if list(verified.columns) != columns or len(verified.index) != len(frame.index):
    os.unlink(destination)
    raise RuntimeError("La verificación del archivo SAV falló")
