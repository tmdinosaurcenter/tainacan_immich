"""Helper for tests/test-workflows.php: print a workflow YAML file as JSON.

Usage: python3 -I tests/workflow-yaml-dump.py <file>
Exit 2 when PyYAML is missing, 1 on a parse error.
"""
import json
import sys

try:
    import yaml
except ImportError:
    sys.exit(2)

if len(sys.argv) != 2:
    sys.exit(1)

try:
    with open(sys.argv[1], encoding="utf-8") as fh:
        data = yaml.safe_load(fh)
except Exception as exc:  # noqa: BLE001
    sys.stderr.write("parse error: %s\n" % exc)
    sys.exit(1)

# YAML 1.1 reads the key "on" as boolean True; json.dumps turns it into "true".
print(json.dumps(data))
