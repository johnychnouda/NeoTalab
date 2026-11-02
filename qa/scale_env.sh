#!/bin/bash
# Runs a command against the scale lab (own MySQL on 3399, API on 8012, mock Meta on 8049). Lab only.
Q="$(cd "$(dirname "$0")" && pwd)"
export QA_ENVFILE="$Q/.lab/scale.env" QA_LABSH="$Q/.lab/scale.sh" QA_STATE="$Q/.lab/scale/state.json" QA_RESULTS="$Q/.lab/scale/results.jsonl" QA_GRAPHLOG="$Q/.lab/scale/graph.jsonl" QA_API="http://127.0.0.1:8012/api/v1"
exec "$@"
