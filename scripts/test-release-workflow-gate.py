#!/usr/bin/env python3
"""Static fail-closed release-workflow regression checks; never runs a release."""
from pathlib import Path
import re

WF=Path(__file__).resolve().parent.parent/".github/workflows"
GATES={"publish-docker.yml":("publish","PUBLISH-DOCKER"),
       "build-kiosk.yml":("build","PUBLISH-KIOSK"),
       "build-health-bridge.yml":("build","PUBLISH-HEALTH-BRIDGE"),
       "build-scale-gateway.yml":("build","PUBLISH-LEGACY-SCALE")}
MUTATORS=[r"(?m)^\s*uses:\s*docker/build-push-action@",
          r"(?m)^\s*uses:\s*softprops/action-gh-release@",
          r"(?m)^\s*gh\s+release\s+(?:create|delete)\b",
          r"(?m)^\s*git\s+push\b",
          r"(?m)^\s*gh\s+api\s+-X\s+DELETE.*releases",
          r"(?m)^\s*packages:\s*write\s*$"]

def block(text,name,indent):
    lines=text.splitlines()
    header=" "*indent+name+":"
    matches=[i for i,line in enumerate(lines) if line==header]
    assert len(matches)==1,f"Expected exactly one {header!r}"
    start=matches[0]+1
    for j in range(start,len(lines)):
        line=lines[j]
        if line.strip() and not line.lstrip().startswith("#") and len(line)-len(line.lstrip())<=indent:
            return "\n".join(lines[start:j])
    return "\n".join(lines[start:])

def verify_manual(name,src,job,phrase):
    triggers=block(src,"on",0)
    names=re.findall(r"(?m)^  ([a-z_]+):\s*$",triggers)
    assert names==["workflow_dispatch"],f"{name}: unexpected triggers {names!r}"
    assert re.search(r"(?m)^      confirm:\s*$",triggers),f"{name}: missing confirm input"
    assert re.search(r"(?m)^        required:\s*true\s*$",triggers),f"{name}: confirm not required"
    assert phrase in triggers,f"{name}: missing phrase"
    expected=("    if: ${{ github.event_name == 'workflow_dispatch'"
              " && github.ref == 'refs/heads/main'"
              " && inputs.confirm == '"+phrase+"' }}")
    actual=re.findall(r"(?m)^    if:.*$",block(src,job,2))
    assert actual==[expected],f"{name}: job missing exact dispatch+main+phrase check; {actual!r}"

def check(files):
    for name,(job,phrase) in GATES.items():
        assert name in files,f"Missing workflow {name}"
        verify_manual(name,files[name],job,phrase)
    assert "type=raw,value=latest,enable=${{ inputs.latest == true }}" in files["publish-docker.yml"],(
        "Docker latest must require an explicit checkbox")
    ci=files["ci.yml"]
    for job in ("auto-merge-to-main","create-release","publish-container"):
        assert not re.search(r"(?m)^  "+re.escape(job)+r":\s*$",ci),f"CI auto-publish job {job}"
    for token in ("softprops/action-gh-release@","docker/build-push-action@",
                  "git push origin main","uses: ./.github/workflows/publish-docker.yml"):
        assert token not in ci,f"CI publishing primitive: {token}"
    assert "python3 scripts/test-release-workflow-gate.py" in ci
    assert re.search(r"(?m)^  push:\s*$",block(ci,"on",0)),"CI push-testing removed"
    for name,src in files.items():
        if any(re.search(p,src) for p in MUTATORS):
            assert name in GATES,f"Unprotected publisher in {name}"
            verify_manual(name,src,*GATES[name])

def mutation_checks(files):
    for name,(job,phrase) in GATES.items():
        original=files[name]
        mutants=[
           original.replace("on:\n  workflow_dispatch:","on:\n  push:\n    branches: [main]\n  workflow_dispatch:",1),
           original.replace(" && github.ref == 'refs/heads/main'","",1),
           original.replace(" && inputs.confirm == '"+phrase+"'","",1)]
        assert all(x!=original for x in mutants)
        for altered in mutants:
            try:check({**files,name:altered})
            except AssertionError:pass
            else:raise AssertionError(f"{name}: unsafe workflow mutation accepted")
    try:check({**files,"ci.yml":files["ci.yml"]+"\n  auto-merge-to-main:\n    runs-on: ubuntu-latest\n"})
    except AssertionError:pass
    else:raise AssertionError("CI auto-merge mutant accepted")

if __name__=="__main__":
    files={f.name:f.read_text() for f in WF.glob("*.yml")}
    check(files)
    print("PASS four manual-only, main-only, confirm-gated release workflows")
    print("PASS no CI automatic develop -> main merge, tag, release or image publishing")
    print("PASS Docker latest requires separate explicit checkbox")
    mutation_checks(files)
    print("PASS negative mutations: unexpected push, missing ref/confirm, auto-merge rejected")
