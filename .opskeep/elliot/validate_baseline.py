#!/usr/bin/env python3
"""Validate an Opskeep Elliot Design security-baseline.yaml (schema 2.2; 2.1 accepted for migration).

Modes
  lint (default)          structure, allowed values, IDs, references, traceability,
                          lifecycle/approval agreement, lock and SPIKE rules, required
                          control-domain coverage, supply-chain and assurance targets.
  --require-approved      CI gate: additionally fail closed unless the baseline is
                          APPROVED for the current design digest and not expired.
  --print-digest          print the design digest and exit (use at approval time).

CI options (with --require-approved)
  --expected-digest HEX   REQUIRED. Digest from an independently protected source
                          (signed attestation, protected environment, approval service).
                          Also read from $EXPECTED_BASELINE_DIGEST.
  --allow-self-asserted   local experiments only: accept approval metadata in the file
                          itself. Never use in a real pipeline.
  --target ENV            development (default), staging or production. The approval
                          scope must name that environment explicitly.
  --stage STAGE           require PASSED evidence for every blocking gate at or
                          before STAGE (e.g. PRE_DEPLOY).

Exit codes: 0 valid, 1 validation/gate failure, 2 cannot read/parse or internal error.
Requires PyYAML (pip install pyyaml).
"""
import argparse
import copy
import datetime as dt
import hashlib
import json
import os
import re
import sys

try:
    import yaml
except ImportError:  # pragma: no cover
    print("ERROR: PyYAML is required (pip install pyyaml)", file=sys.stderr)
    sys.exit(2)

SUPPORTED_SCHEMAS = {"2.1", "2.2", "2.3"}
CURRENT_SCHEMA = "2.3"
LEVELS = ["LOW", "MEDIUM", "HIGH", "CRITICAL"]
MODES = ["FAST_TRACK", "STANDARD", "FULL", "SPIKE"]
MIN_MODE = {"LOW": "FAST_TRACK", "MEDIUM": "STANDARD", "HIGH": "FULL", "CRITICAL": "FULL"}
MODE_RANK = {"FAST_TRACK": 0, "STANDARD": 1, "FULL": 2}
LIFECYCLE = ["DRAFT", "REVIEW_REQUIRED", "APPROVED", "REJECTED", "SUSPENDED"]
POSTURE = ["ELIGIBLE_WHEN_APPROVED", "PRODUCTION_PROHIBITED"]
DEV_LOCK = ["LOCKED", "SANDBOX_ONLY", "UNLOCKED"]
DEPLOY_LOCK = ["LOCKED", "UNLOCKED"]
CLASSIFICATION = ["PUBLIC", "INTERNAL", "CONFIDENTIAL", "SENSITIVE", "RESTRICTED"]
EXPOSURE = ["INTERNET", "INTERNAL", "PRIVATE", "THIRD_PARTY"]
IDENTITY_TYPES = ["HUMAN", "WORKLOAD", "AGENT"]
THREAT_STATUS = ["OPEN", "MITIGATED", "ACCEPTED", "TRANSFERRED", "RETIRED"]
CONTROL_STATUS = ["REQUIRED", "PLANNED", "IMPLEMENTED", "VERIFIED", "NOT_APPLICABLE", "UNRESOLVED", "RETIRED"]
STAGES = ["PRE_IMPLEMENTATION", "PULL_REQUEST", "MERGE", "BUILD", "PRE_DEPLOY", "DEPLOY", "RUNTIME"]
GATE_STATUS = ["NOT_YET_BUILT", "CONFIGURED", "VERIFIED", "DISABLED", "RETIRED"]
DECISION_STATUS = ["OPEN", "DECIDED", "DEFERRED", "RETIRED"]
EVIDENCE_TYPES = ["OWNER_APPROVAL", "CI_RUN", "TEST_RESULT", "SCAN_REPORT", "CONFIG_INSPECTION", "MANUAL_REVIEW", "ATTESTATION"]
EVIDENCE_STATUS = ["NOT_YET_BUILT", "PENDING", "PASSED", "FAILED", "STALE"]
HANDOFF_STATUS = ["NOT_CONFIGURED", "PLANNED", "CONFIGURED", "VERIFIED"]
SIGNAL_STATUS = ["PLANNED", "CONFIGURED", "VERIFIED", "NOT_APPLICABLE", "RETIRED"]
CHANGE_TYPES = ["ADDED", "REMOVED", "MODIFIED"]
CHANGE_STATUS = ["PROPOSED", "ASSESSED", "APPROVED", "REJECTED"]
APPROVAL_DECISION = ["PENDING", "APPROVED", "REJECTED", "SUSPENDED"]
LIFECYCLE_TO_DECISION = {
    "DRAFT": "PENDING", "REVIEW_REQUIRED": "PENDING", "APPROVED": "APPROVED",
    "REJECTED": "REJECTED", "SUSPENDED": "SUSPENDED",
}
RECOVERY_ITEMS = ["backup", "restore", "rollback", "incident_response"]

# ---- schema 2.2 additions -------------------------------------------------
CONTROL_DOMAINS = [
    "AUTH", "AUTHZ", "SESSION", "INPUT", "API", "FILE", "FRONTEND", "CRYPTO", "SECRETS",
    "CONFIG", "DATA", "ERROR_HANDLING", "LOGGING", "SUPPLY_CHAIN", "DEPLOY", "RECOVERY",
    "AI_AGENT", "GOVERNANCE",
]
_LOW_DOMAINS = ["INPUT", "SECRETS", "CONFIG", "SUPPLY_CHAIN", "LOGGING", "DEPLOY", "GOVERNANCE"]
_MEDIUM_DOMAINS = _LOW_DOMAINS + ["AUTH", "AUTHZ", "SESSION", "API", "CRYPTO", "DATA", "ERROR_HANDLING", "FRONTEND"]
_HIGH_DOMAINS = _MEDIUM_DOMAINS + ["FILE", "RECOVERY", "AI_AGENT"]
REQUIRED_DOMAINS = {"LOW": _LOW_DOMAINS, "MEDIUM": _MEDIUM_DOMAINS, "HIGH": _HIGH_DOMAINS, "CRITICAL": _HIGH_DOMAINS}
# Skill policy (not text from ASVS or SLSA): minimum assurance targets per tier.
MIN_ASVS = {"LOW": "L1", "MEDIUM": "L2", "HIGH": "L2", "CRITICAL": "L3"}
MIN_SLSA_BUILD = {"LOW": 1, "MEDIUM": 1, "HIGH": 2, "CRITICAL": 3}
ASVS_LEVELS = ["L1", "L2", "L3"]
SBOM_FORMATS = ["CYCLONEDX", "SPDX", "NONE"]
REGULATORY_STATUS = ["APPLICABLE", "NOT_APPLICABLE", "NOT_ASSESSED"]
SCOPE_RE = re.compile(r"^(sandbox|development|staging|production)(:.+)?$", re.IGNORECASE)
REF_RE = re.compile(r"^(ASVS-5\.0|OWASP-T10-2025|OWASP-LLM-2025|OWASP-ASI-2026|SSDF-1\.1|SLSA-1\.2|CISA-SBD):[A-Za-z0-9._-]+$")
ISO_DT_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$")
CROSSWALK_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "references", "standards-crosswalk.yaml")

# Statuses that only record implementation progress. They are folded to ACTIVE in the
# schema 2.2 digest so progress never invalidates an approval, while weakening states
# (RETIRED, NOT_APPLICABLE, UNRESOLVED, DISABLED, ACCEPTED, TRANSFERRED) stay in the digest.
PROGRESS_STATUSES = {
    "controls": {"REQUIRED", "PLANNED", "IMPLEMENTED", "VERIFIED"},
    "gates": {"NOT_YET_BUILT", "CONFIGURED", "VERIFIED"},
    "threats": {"OPEN", "MITIGATED"},
    "signals": {"PLANNED", "CONFIGURED", "VERIFIED"},
    "recovery": {"REQUIRED", "PLANNED", "IMPLEMENTED", "VERIFIED"},
    "supply_chain": {"REQUIRED", "PLANNED", "IMPLEMENTED", "VERIFIED"},
}

TOP_LEVEL_21 = [
    "schema_version", "baseline_version", "record_digest", "project", "risk", "lifecycle",
    "classification_levels", "assets", "components", "trust_boundaries", "data_flows",
    "identities", "threats", "controls", "gates", "decisions", "evidence", "monitoring",
    "recovery", "approval", "changes",
]
TOP_LEVEL_22 = TOP_LEVEL_21 + ["supply_chain"]
TOP_LEVEL_23 = TOP_LEVEL_22 + ["capabilities", "source", "engineering"]   # ai_profile is optional unless AI is present

# ---- schema 2.3: declared engineering practices -------------------------------------------
# Each practice is a map {status, practice} (or {status: NOT_APPLICABLE, reason}). The validator
# can require that a practice is stated at the right tier; it cannot judge how good it is.
# key -> (minimum tier, what to state, standards touched)
ENGINEERING_PRACTICES = {
    "toolchain_security":        ("MEDIUM", "how CI and build tooling is secured (pinned versions, least-privilege tokens, isolated runners)", "SSDF PO.3"),
    "dev_environment":           ("MEDIUM", "how developer and build environments are protected (MFA on the source host, secret handling, no production credentials in development)", "SSDF PO.5"),
    "secure_coding":             ("MEDIUM", "the secure-coding standard or review rubric the team follows", "SSDF PW.5, ASVS V15"),
    "build_hardening":           ("HIGH",   "compiler, interpreter and build hardening (strict mode, safe flags, reproducible or verifiable builds)", "SSDF PW.6"),
    "code_review":               ("MEDIUM", "how code is reviewed or statically analysed for vulnerabilities", "SSDF PW.7"),
    "security_testing":          ("MEDIUM", "the security testing strategy (static, dynamic, dependency, fuzz, abuse-case tests)", "SSDF PW.8"),
    "release_archive":           ("MEDIUM", "how each release and its provenance are archived and for how long", "SSDF PS.3"),
    "data_integrity":            ("MEDIUM", "how integrity of stored data, updates and serialized input is protected (signatures, safe deserialization, tamper evidence)", "OWASP A08"),
    "vulnerability_class_reduction": ("MEDIUM", "the plan to remove whole vulnerability classes (framework-level fixes, memory-safe language choice or roadmap)", "CISA Secure by Design"),
}
AI_PRACTICES = {
    "model_and_tool_provenance": "where models, datasets, MCP servers, plugins and skills come from and how they are pinned and reviewed (LLM03, ASI04)",
    "data_integrity":            "how training, fine-tuning, retrieval and memory data is protected from poisoning (LLM04)",
    "prompt_confidentiality":    "what is in system prompts and why none of it is a secret or authorization logic (LLM07)",
    "retrieval_store_security":  "access control and tenant isolation on vector and retrieval stores (LLM08)",
    "output_assurance":          "how output accuracy is checked or reviewed before it is relied on (LLM09)",
    "memory_policy":             "how agent memory is written, reviewed and expired (ASI06)",
    "inter_agent_security":      "how agents authenticate and validate messages from other agents (ASI07)",
    "cascade_limits":            "limits that stop one failure spreading: step budgets, circuit breakers, isolation (ASI08)",
    "human_trust":               "how users are told what the agent did and asked to confirm consequential actions (ASI09)",
    "rogue_agent_controls":      "kill switch and monitoring for an agent acting outside its intended behaviour (ASI10)",
}
CAPABILITY_FLAGS = {
    "handles_files":              ("FILE domain control and a file_handling practice", "ASVS V5"),
    "uses_self_contained_tokens": ("a token_validation practice (signature, audience, expiry, revocation)", "ASVS V9"),
    "uses_federated_login":       ("a federated_login practice (redirect validation, state/nonce, PKCE, token handling)", "ASVS V10"),
}
CAPABILITY_PRACTICE = {"handles_files": "file_handling", "uses_self_contained_tokens": "token_validation",
                       "uses_federated_login": "federated_login"}
MIN_SLSA_SOURCE = {"LOW": 1, "MEDIUM": 2, "HIGH": 3, "CRITICAL": 4}
REVIEW_POLICIES = ["NONE", "ONE_REVIEWER", "TWO_PARTY"]
TIER_RANK = {"LOW": 0, "MEDIUM": 1, "HIGH": 2, "CRITICAL": 3}
# project.intake: the project gate answered before design starts (constraint first, then the bet)
INTAKE_FORCES = ["push", "pull", "anxiety", "habit"]
# collection -> required ID prefix
COLLECTIONS = {
    "assets": "AST-", "components": "CMP-", "trust_boundaries": "TB-", "data_flows": "FLW-",
    "identities": "ID-", "threats": "THR-", "controls": "CTL-", "gates": "GATE-",
    "decisions": "DEC-", "evidence": "EVD-", "changes": "CHG-",
}
SECRET_PATTERNS = [
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "private key block"),
    (re.compile(r"\bAKIA[0-9A-Z]{16}\b"), "AWS access key id"),
    (re.compile(r"\bgh[pousr]_[A-Za-z0-9]{30,}\b"), "GitHub token"),
    (re.compile(r"\bsk-(?:ant-|live_|proj-)?[A-Za-z0-9_-]{20,}\b"), "API secret key"),
    (re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b"), "Slack token"),
    (re.compile(r"(?i)\b(password|passwd|secret|api_key|token)\s*[:=]\s*['\"]?[^\s'\"]{8,}"), "credential assignment"),
]
SEMVER = re.compile(r"^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
HEX64 = re.compile(r"^[0-9a-f]{64}$")


class Report:
    def __init__(self):
        self.errors, self.warnings = [], []

    def err(self, msg):
        self.errors.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)


def now_utc():
    return dt.datetime.now(dt.timezone.utc)


def parse_date(value):
    if value is None:
        return None
    if isinstance(value, dt.datetime):
        return value if value.tzinfo else value.replace(tzinfo=dt.timezone.utc)
    if isinstance(value, dt.date):
        return dt.datetime(value.year, value.month, value.day, tzinfo=dt.timezone.utc)
    s = str(value).strip()
    if s.endswith("Z"):
        s = s[:-1] + "+00:00"
    parsed = dt.datetime.fromisoformat(s)  # raises ValueError if invalid
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=dt.timezone.utc)


def _canon_value(obj, canonical_time):
    if isinstance(obj, dict):
        return {str(k): _canon_value(v, canonical_time) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_canon_value(v, canonical_time) for v in obj]
    if isinstance(obj, dt.datetime):
        if canonical_time:
            return parse_date(obj).astimezone(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        return obj.isoformat()
    if isinstance(obj, dt.date):
        return obj.isoformat()
    if canonical_time and isinstance(obj, str) and ISO_DT_RE.match(obj):
        try:
            return parse_date(obj).astimezone(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        except ValueError:
            return obj
    return obj


def _scope_tokens(scope):
    """Environment tokens named by approval.scope entries (the part before ':')."""
    out = []
    for s in scope or []:
        m = SCOPE_RE.match(str(s).strip())
        if m:
            out.append(m.group(1).lower())
    return out


def _legacy_digest(doc):
    """Schema 2.1 digest, kept so existing approvals keep verifying until migrated."""
    d = copy.deepcopy(doc)
    for key in ("record_digest", "approval", "evidence"):
        d.pop(key, None)
    lc = d.get("lifecycle") or {}
    for key in ("status", "development_lock", "deployment_lock"):
        lc.pop(key, None)
    mon = d.get("monitoring") or {}
    mon.pop("handoff", None)
    for coll in ("controls", "gates", "threats"):
        for rec in d.get(coll) or []:
            if isinstance(rec, dict):
                rec.pop("status", None)
                rec.pop("evidence_ids", None)
    for rec in mon.get("signals") or []:
        if isinstance(rec, dict):
            rec.pop("status", None)
            rec.pop("evidence_ids", None)
    rec_block = d.get("recovery") or {}
    for item in RECOVERY_ITEMS:
        if isinstance(rec_block.get(item), dict):
            rec_block[item].pop("status", None)
    canonical = json.dumps(_canon_value(d, False), sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def _fold(rec, kind):
    if isinstance(rec, dict):
        if rec.get("status") in PROGRESS_STATUSES[kind]:
            rec["status"] = "ACTIVE"
        rec.pop("evidence_ids", None)


def design_digest(doc):
    """SHA-256 over the reviewed design (schema 2.2).

    Bound: everything except approval metadata, evidence, locks and handoff records.
    Also bound: the approval scope (so it cannot be widened without re-approval) and every
    weakening status (RETIRED, NOT_APPLICABLE, UNRESOLVED, DISABLED, ACCEPTED, TRANSFERRED).
    Folded: pure progress statuses (PLANNED -> IMPLEMENTED -> VERIFIED, and so on) -> ACTIVE.
    Timestamps are canonicalised to UTC 'YYYY-MM-DDTHH:MM:SSZ' so quoting/offset style is irrelevant.
    """
    if str(doc.get("schema_version")) == "2.1":
        return _legacy_digest(doc)
    d = copy.deepcopy(doc)
    for key in ("record_digest", "evidence"):
        d.pop(key, None)
    appr = d.pop("approval", None)
    appr = appr if isinstance(appr, dict) else {}
    scope = appr.get("scope") if isinstance(appr.get("scope"), list) else []
    d["approval_scope"] = sorted(str(s).strip().lower() for s in scope)
    lc = d.get("lifecycle")
    if isinstance(lc, dict):
        for key in ("status", "development_lock", "deployment_lock"):
            lc.pop(key, None)
    mon = d.get("monitoring")
    if isinstance(mon, dict):
        mon.pop("handoff", None)
        for rec in mon.get("signals") or []:
            _fold(rec, "signals")
    for coll in ("controls", "gates", "threats"):
        for rec in d.get(coll) or []:
            _fold(rec, coll)
    rec_block = d.get("recovery")
    if isinstance(rec_block, dict):
        for item in RECOVERY_ITEMS:
            _fold(rec_block.get(item), "recovery")
    sc = d.get("supply_chain")
    if isinstance(sc, dict):
        for key in ("sbom", "provenance", "vulnerability_management", "build_hardening"):
            _fold(sc.get(key), "supply_chain")
    for block_name in ("engineering", "ai_profile"):
        blk = d.get(block_name)
        if isinstance(blk, dict):
            for key in list(blk):
                _fold(blk[key], "supply_chain")
    canonical = json.dumps(_canon_value(d, True), sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def check_enum(r, where, value, allowed, required=True):
    if value is None:
        if required:
            r.err(f"{where}: missing (allowed: {', '.join(allowed)})")
        return
    if value not in allowed:
        r.err(f"{where}: '{value}' not allowed (allowed: {', '.join(allowed)})")


def active(rec):
    return rec.get("status") != "RETIRED"


def load_crosswalk_ids():
    """Return the set of 'STANDARD:ITEM' ids in the bundled crosswalk, or None if unavailable."""
    try:
        with open(CROSSWALK_PATH, encoding="utf-8") as fh:
            cw = yaml.safe_load(fh)
        ids = set()
        for std_id, std in (cw.get("standards") or {}).items():
            for item in std.get("items") or []:
                ids.add(f"{std_id}:{item['id']}")
        return ids
    except (OSError, yaml.YAMLError, AttributeError, KeyError, TypeError):
        return None


def validate(doc, raw_text):
    r = Report()
    if not isinstance(doc, dict):
        r.err("document root must be a mapping")
        return r, {}

    schema = str(doc.get("schema_version"))
    v22 = schema in ("2.2", "2.3")
    v23 = schema == "2.3"
    top = TOP_LEVEL_23 if v23 else TOP_LEVEL_22 if v22 else TOP_LEVEL_21
    optional_top = ["ai_profile"] if v23 else []
    for key in top:
        if key not in doc:
            r.err(f"missing top-level key '{key}'")
    for key in doc:
        if key not in top and key not in optional_top:
            r.warn(f"unknown top-level key '{key}'")

    if schema not in SUPPORTED_SCHEMAS:
        r.err(f"schema_version '{doc.get('schema_version')}' unsupported (supported: {', '.join(sorted(SUPPORTED_SCHEMAS))})")
    elif schema == "2.1":
        r.warn("schema 2.1 is legacy: migrate to 2.3 (see baseline-contract.md); 2.1 lacks supply-chain, assurance and domain checks")
    elif schema == "2.2":
        r.warn("schema 2.2 is superseded: migrate to 2.3 for engineering-practice, source-control and AI-profile checks")
    if not SEMVER.match(str(doc.get("baseline_version", ""))):
        r.err("baseline_version must be semver (e.g. 1.0.0)")

    for pat, label in SECRET_PATTERNS:
        if pat.search(raw_text):
            r.err(f"possible secret in baseline ({label}); baselines must never store secrets")

    # ---- structural guard: wrong shapes become clean errors, never crashes ----
    structural = False

    def as_map(where, value):
        nonlocal structural
        if value is None:
            return {}
        if not isinstance(value, dict):
            r.err(f"{where}: must be a mapping")
            structural = True
            return {}
        return value

    def as_list(where, value):
        nonlocal structural
        if value is None:
            return []
        if not isinstance(value, list):
            r.err(f"{where}: must be a list")
            structural = True
            return []
        clean = []
        for i, rec in enumerate(value):
            if not isinstance(rec, dict):
                r.err(f"{where}[{i}]: must be a mapping")
                structural = True
            else:
                clean.append(rec)
        return clean

    project = as_map("project", doc.get("project"))
    risk = as_map("risk", doc.get("risk"))
    lc = as_map("lifecycle", doc.get("lifecycle"))
    appr = as_map("approval", doc.get("approval"))
    mon = as_map("monitoring", doc.get("monitoring"))
    rec_block = as_map("recovery", doc.get("recovery"))
    sc = as_map("supply_chain", doc.get("supply_chain"))
    caps = as_map("capabilities", doc.get("capabilities"))
    src = as_map("source", doc.get("source"))
    eng = as_map("engineering", doc.get("engineering"))
    aip = as_map("ai_profile", doc.get("ai_profile"))
    ho = as_map("monitoring.handoff", mon.get("handoff"))
    colls = {name: as_list(name, doc.get(name)) for name in COLLECTIONS}
    signals = as_list("monitoring.signals", mon.get("signals"))
    if structural:
        return r, {}

    # ---- collections, IDs, index ----
    index = {}
    for coll, prefix in COLLECTIONS.items():
        for i, rec in enumerate(colls[coll]):
            rid = rec.get("id")
            if not rid or not str(rid).startswith(prefix):
                r.err(f"{coll}[{i}]: id '{rid}' must start with '{prefix}'")
                continue
            if rid in index:
                r.err(f"duplicate id '{rid}'")
            index[rid] = (coll, rec)
    for i, rec in enumerate(signals):
        rid = rec.get("id")
        if not rid or not str(rid).startswith("MON-"):
            r.err(f"monitoring.signals[{i}]: id '{rid}' must start with 'MON-'")
            continue
        if rid in index:
            r.err(f"duplicate id '{rid}'")
        index[rid] = ("signals", rec)

    def resolve(where, ids, expect=None):
        if ids is None:
            return []
        if not isinstance(ids, list):
            r.err(f"{where}: must be a list of IDs")
            return []
        out = []
        for ref in ids:
            if ref not in index:
                r.err(f"{where}: reference '{ref}' does not exist")
            elif expect and index[ref][0] != expect:
                r.err(f"{where}: '{ref}' is a {index[ref][0][:-1]}, expected {expect[:-1]}")
            else:
                out.append(index[ref][1])
        return out

    # ---- risk / lifecycle ----
    tier, mode = risk.get("tier"), risk.get("mode")
    status = lc.get("status")
    check_enum(r, "risk.tier", tier, LEVELS)
    check_enum(r, "risk.mode", mode, MODES)
    check_enum(r, "lifecycle.status", status, LIFECYCLE)
    check_enum(r, "lifecycle.production_posture", lc.get("production_posture"), POSTURE)
    check_enum(r, "lifecycle.development_lock", lc.get("development_lock"), DEV_LOCK)
    check_enum(r, "lifecycle.deployment_lock", lc.get("deployment_lock"), DEPLOY_LOCK)
    check_enum(r, "risk.likely_production_tier", risk.get("likely_production_tier"), LEVELS, required=(mode == "SPIKE"))
    if "expires_at" in lc:
        r.err("lifecycle.expires_at was renamed in schema 2.1: use lifecycle.spike_expires_at (SPIKE sandbox) or approval.expires_at")

    strict = status not in (None, "DRAFT")
    trace = r.err if strict else r.warn

    if tier in MIN_MODE and mode in MODE_RANK and MODE_RANK[mode] < MODE_RANK[MIN_MODE[tier]]:
        r.err(f"risk.mode {mode} is below the minimum {MIN_MODE[tier]} for tier {tier}")
    if strict and not risk.get("rationale"):
        r.err("risk.rationale: state why this tier/mode was chosen")

    if mode == "SPIKE":
        if lc.get("production_posture") != "PRODUCTION_PROHIBITED":
            r.err("SPIKE requires production_posture PRODUCTION_PROHIBITED")
        if lc.get("deployment_lock") != "LOCKED":
            r.err("SPIKE requires deployment_lock LOCKED")
        if lc.get("development_lock") == "UNLOCKED":
            r.err("SPIKE development_lock must be SANDBOX_ONLY or LOCKED, never UNLOCKED")
        if not lc.get("spike_expires_at"):
            r.err("SPIKE requires lifecycle.spike_expires_at")
    else:
        if lc.get("development_lock") == "SANDBOX_ONLY":
            r.err("development_lock SANDBOX_ONLY is only valid for SPIKE")
        if lc.get("spike_expires_at") is not None:
            r.err("lifecycle.spike_expires_at must be null outside SPIKE")
    if lc.get("spike_expires_at") is not None:
        try:
            exp = parse_date(lc["spike_expires_at"])
            if mode == "SPIKE" and exp < now_utc():
                r.err(f"SPIKE sandbox expired on {exp.date().isoformat()}: destroy it or re-review before continuing")
        except ValueError:
            r.err("lifecycle.spike_expires_at is not ISO 8601")

    if status != "APPROVED":
        if lc.get("development_lock") == "UNLOCKED":
            r.err(f"development_lock UNLOCKED while lifecycle is {status}")
        if lc.get("deployment_lock") == "UNLOCKED":
            r.err(f"deployment_lock UNLOCKED while lifecycle is {status}")
    if lc.get("deployment_lock") == "UNLOCKED" and lc.get("production_posture") == "PRODUCTION_PROHIBITED":
        r.err("deployment_lock UNLOCKED contradicts PRODUCTION_PROHIBITED")

    # ---- approval scope (schema 2.2: explicit environment tokens) ----
    scope = appr.get("scope")
    if scope is not None and not isinstance(scope, list):
        r.err("approval.scope must be a list")
        scope = []
    scope = scope or []
    if v22:
        for s in scope:
            if not SCOPE_RE.match(str(s).strip()):
                r.err(f"approval.scope entry '{s}' must start with an environment token "
                      f"(sandbox, development, staging, production), optionally followed by ':detail'")
        if mode == "SPIKE" and any(t in ("staging", "production") for t in _scope_tokens(scope)):
            r.err("SPIKE approval.scope may name only sandbox/development, never staging or production")

    # ---- records ----
    ai_present = False
    for rec in colls["assets"]:
        check_enum(r, f"{rec.get('id')}.classification", rec.get("classification"), CLASSIFICATION)
    for rec in colls["components"]:
        check_enum(r, f"{rec.get('id')}.exposure", rec.get("exposure"), EXPOSURE)
        if rec.get("ai_capability") is True and active(rec):
            ai_present = True
        if v22 and "ai_capability" in rec and not isinstance(rec["ai_capability"], bool):
            r.err(f"{rec.get('id')}.ai_capability must be true or false")
    for rec in colls["trust_boundaries"]:
        to = rec.get("to")
        if isinstance(to, list):
            resolve(f"{rec.get('id')}.to", to, "components")
    for rec in colls["data_flows"]:
        check_enum(r, f"{rec.get('id')}.classification", rec.get("classification"), CLASSIFICATION)
        resolve(f"{rec.get('id')}.boundaries", rec.get("boundaries"), "trust_boundaries")
    for rec in colls["identities"]:
        rid = rec.get("id")
        check_enum(r, f"{rid}.type", rec.get("type"), IDENTITY_TYPES)
        resolve(f"{rid}.resources", rec.get("resources"))
        if rec.get("type") == "AGENT" and active(rec):
            ai_present = True
        if not rec.get("forbidden_actions"):
            trace(f"{rid}: list forbidden_actions (authentication never implies authorization)")

    evidence = {e.get("id"): e for e in colls["evidence"]}
    for rid, e in evidence.items():
        check_enum(r, f"{rid}.type", e.get("type"), EVIDENCE_TYPES)
        check_enum(r, f"{rid}.status", e.get("status"), EVIDENCE_STATUS)
        if e.get("status") in ("PASSED", "FAILED", "STALE"):
            for f in ("observed_at", "observer", "location"):
                if not e.get(f):
                    r.err(f"{rid}: status {e.get('status')} requires '{f}' (never invent evidence)")
            try:
                parse_date(e.get("observed_at"))
            except (ValueError, TypeError):
                r.err(f"{rid}.observed_at is not ISO 8601")

    def has_passed(where, ids):
        recs = resolve(where, ids, "evidence")
        return bool(recs) and all(x.get("status") == "PASSED" for x in recs)

    crosswalk_ids = load_crosswalk_ids() if v22 else None
    warned_crosswalk = False

    def check_refs(where, refs):
        nonlocal warned_crosswalk
        if refs is None:
            return
        if not isinstance(refs, list):
            r.err(f"{where}.refs must be a list")
            return
        for ref in refs:
            if not REF_RE.match(str(ref)):
                r.err(f"{where}.refs: '{ref}' is not a recognised standard reference (STANDARD:ITEM)")
            elif crosswalk_ids is not None and str(ref) not in crosswalk_ids:
                r.err(f"{where}.refs: '{ref}' is not in references/standards-crosswalk.yaml")
            elif crosswalk_ids is None and not warned_crosswalk:
                r.warn("references/standards-crosswalk.yaml not found: standard references were format-checked only")
                warned_crosswalk = True

    control_status = {c.get("id"): c.get("status") for c in colls["controls"]}
    referenced_controls = set()
    for t in colls["threats"]:
        rid = t.get("id")
        check_enum(r, f"{rid}.status", t.get("status"), THREAT_STATUS)
        for f in ("likelihood", "impact", "residual_risk"):
            check_enum(r, f"{rid}.{f}", t.get(f), LEVELS)
        resolve(f"{rid}.component_ids", t.get("component_ids"), "components")
        ctls = resolve(f"{rid}.control_ids", t.get("control_ids"), "controls")
        referenced_controls.update(c.get("id") for c in ctls)
        check_refs(rid, t.get("refs"))
        if active(t):
            if not t.get("control_ids"):
                trace(f"{rid}: active threat has no controls")
            for c in ctls:
                if c.get("status") == "RETIRED":
                    trace(f"{rid}: active threat references RETIRED control {c.get('id')}; retire or re-map the threat first")
        if t.get("status") == "MITIGATED" and any(c.get("status") not in ("IMPLEMENTED", "VERIFIED") for c in ctls):
            r.err(f"{rid}: MITIGATED requires all linked controls IMPLEMENTED or VERIFIED")
        if t.get("status") == "ACCEPTED" and not (t.get("owner") and t.get("acceptance_reason")):
            r.err(f"{rid}: ACCEPTED requires owner and acceptance_reason")

    gates = colls["gates"]
    for g in gates:
        rid = g.get("id")
        check_enum(r, f"{rid}.stage", g.get("stage"), STAGES)
        check_enum(r, f"{rid}.status", g.get("status"), GATE_STATUS)
        if not isinstance(g.get("blocking"), bool):
            r.err(f"{rid}.blocking must be true or false")
        ctls = resolve(f"{rid}.control_ids", g.get("control_ids"), "controls")
        referenced_controls.update(c.get("id") for c in ctls)
        resolve(f"{rid}.required_evidence", g.get("required_evidence"), "evidence")
        if active(g):
            if not g.get("control_ids"):
                trace(f"{rid}: active gate traces to no control")
            if not g.get("required_evidence"):
                trace(f"{rid}: active gate lists no required evidence")
            for c in ctls:
                if c.get("status") == "RETIRED":
                    trace(f"{rid}: active gate references RETIRED control {c.get('id')}; retire or re-map the gate first")
        if g.get("status") == "VERIFIED" and not has_passed(f"{rid}.evidence_ids", g.get("evidence_ids")):
            r.err(f"{rid}: VERIFIED requires evidence_ids that are all PASSED")
        if g.get("status") == "DISABLED" and g.get("blocking") and status == "APPROVED":
            r.err(f"{rid}: blocking gate is DISABLED on an APPROVED baseline")

    blocking_unresolved = []
    domains_addressed = set()
    for c in colls["controls"]:
        rid = c.get("id")
        st = c.get("status")
        check_enum(r, f"{rid}.status", st, CONTROL_STATUS)
        if v22:
            check_enum(r, f"{rid}.domain", c.get("domain"), CONTROL_DOMAINS)
        check_refs(rid, c.get("refs"))
        if st == "VERIFIED" and not has_passed(f"{rid}.evidence_ids", c.get("evidence_ids")):
            r.err(f"{rid}: VERIFIED requires evidence_ids that are all PASSED")
        if st == "NOT_APPLICABLE" and not c.get("reason"):
            r.err(f"{rid}: NOT_APPLICABLE requires a reason")
        if st == "UNRESOLVED":
            if not isinstance(c.get("blocks_approval"), bool):
                r.err(f"{rid}: UNRESOLVED requires blocks_approval true/false")
            elif c["blocks_approval"]:
                blocking_unresolved.append(rid)
        if active(c) and st != "NOT_APPLICABLE" and rid not in referenced_controls:
            trace(f"{rid}: control is not referenced by any threat or gate")
        if active(c) and st != "UNRESOLVED" and not (st == "NOT_APPLICABLE" and not c.get("reason")):
            domains_addressed.add(c.get("domain"))

    # required control-domain coverage (schema 2.2)
    if v22 and mode != "SPIKE" and tier in REQUIRED_DOMAINS:
        required = list(REQUIRED_DOMAINS[tier])
        if ai_present and "AI_AGENT" not in required:
            required.append("AI_AGENT")
        if v23 and caps.get("handles_files") is True and "FILE" not in required:
            required.append("FILE")
        missing = [d for d in required if d not in domains_addressed]
        if missing:
            trace(f"controls missing for required domains at tier {tier}: {', '.join(missing)} "
                  f"(add a control, or a NOT_APPLICABLE control with a reason)")

    for item in RECOVERY_ITEMS:
        entry = rec_block.get(item)
        if not isinstance(entry, dict):
            r.err(f"recovery.{item}: missing")
            continue
        check_enum(r, f"recovery.{item}.status", entry.get("status"), CONTROL_STATUS)
        if entry.get("status") == "NOT_APPLICABLE" and not entry.get("reason"):
            r.err(f"recovery.{item}: NOT_APPLICABLE requires a reason")
        if entry.get("status") == "UNRESOLVED" and entry.get("blocks_approval") is True:
            blocking_unresolved.append(f"recovery.{item}")

    blocking_decisions = []
    for d in colls["decisions"]:
        rid = d.get("id")
        check_enum(r, f"{rid}.status", d.get("status"), DECISION_STATUS)
        if not isinstance(d.get("blocks_approval"), bool):
            r.err(f"{rid}.blocks_approval must be true or false")
        if d.get("blocks_approval") and d.get("status") in ("OPEN", "DEFERRED"):
            blocking_decisions.append(rid)

    check_enum(r, "monitoring.handoff.configuration_status", ho.get("configuration_status"), HANDOFF_STATUS)
    if ho.get("baseline_version") not in (None, doc.get("baseline_version")):
        r.err("monitoring.handoff.baseline_version does not match baseline_version")
    if ho.get("provider") in (None, "", "NONE") and ho.get("configuration_status") in ("CONFIGURED", "VERIFIED"):
        r.err("monitoring.handoff: provider NONE cannot be CONFIGURED/VERIFIED")
    for s in signals:
        check_enum(r, f"{s.get('id')}.status", s.get("status"), SIGNAL_STATUS)

    for ch in colls["changes"]:
        rid = ch.get("id")
        check_enum(r, f"{rid}.type", ch.get("type"), CHANGE_TYPES)
        check_enum(r, f"{rid}.status", ch.get("status"), CHANGE_STATUS)
        check_enum(r, f"{rid}.security_impact", ch.get("security_impact"), ["NONE"] + LEVELS)
        if not isinstance(ch.get("material"), bool):
            r.err(f"{rid}.material must be true or false")
        resolve(f"{rid}.targets", ch.get("targets"))

    # ---- schema 2.2: assurance, supply chain, vulnerability management, regulatory ----
    if v22:
        assurance = as_map("risk.assurance", risk.get("assurance"))
        if not assurance:
            r.err("risk.assurance: required in schema 2.2 (asvs_level, slsa_build_level)")
        else:
            asvs = assurance.get("asvs_level")
            check_enum(r, "risk.assurance.asvs_level", asvs, ASVS_LEVELS + ["NOT_APPLICABLE"])
            if asvs == "NOT_APPLICABLE" and not assurance.get("asvs_reason"):
                r.err("risk.assurance.asvs_level NOT_APPLICABLE requires asvs_reason")
            slsa = assurance.get("slsa_build_level")
            if not (isinstance(slsa, int) and not isinstance(slsa, bool) and 0 <= slsa <= 3):
                r.err("risk.assurance.slsa_build_level must be an integer 0-3")
            if mode != "SPIKE" and tier in MIN_ASVS:
                deviation = assurance.get("deviation_reason")
                if asvs in ASVS_LEVELS and ASVS_LEVELS.index(asvs) < ASVS_LEVELS.index(MIN_ASVS[tier]):
                    (r.warn if deviation else r.err)(
                        f"risk.assurance.asvs_level {asvs} is below the policy minimum {MIN_ASVS[tier]} for tier {tier}"
                        + (" (deviation_reason recorded)" if deviation else " (add deviation_reason to accept the gap)"))
                if isinstance(slsa, int) and not isinstance(slsa, bool) and slsa < MIN_SLSA_BUILD[tier]:
                    (r.warn if deviation else r.err)(
                        f"risk.assurance.slsa_build_level {slsa} is below the policy minimum {MIN_SLSA_BUILD[tier]} for tier {tier}"
                        + (" (deviation_reason recorded)" if deviation else " (add deviation_reason to accept the gap)"))

        sbom = as_map("supply_chain.sbom", sc.get("sbom"))
        prov = as_map("supply_chain.provenance", sc.get("provenance"))
        dep = as_map("supply_chain.dependency_policy", sc.get("dependency_policy"))
        vm = as_map("supply_chain.vulnerability_management", sc.get("vulnerability_management"))
        for name, blk in (("sbom", sbom), ("provenance", prov), ("dependency_policy", dep), ("vulnerability_management", vm)):
            if not blk:
                r.err(f"supply_chain.{name}: required in schema 2.2")
        if sbom:
            check_enum(r, "supply_chain.sbom.status", sbom.get("status"), CONTROL_STATUS)
            check_enum(r, "supply_chain.sbom.format", sbom.get("format"), SBOM_FORMATS)
            if not isinstance(sbom.get("required"), bool):
                r.err("supply_chain.sbom.required must be true or false")
            elif sbom["required"] and sbom.get("format") == "NONE":
                r.err("supply_chain.sbom: required is true but format is NONE")
            if sbom.get("status") == "NOT_APPLICABLE" and not sbom.get("reason"):
                r.err("supply_chain.sbom: NOT_APPLICABLE requires a reason")
            if tier in ("HIGH", "CRITICAL") and mode != "SPIKE" and sbom.get("required") is not True:
                trace(f"supply_chain.sbom.required must be true at tier {tier}")
        if prov:
            check_enum(r, "supply_chain.provenance.status", prov.get("status"), CONTROL_STATUS)
            if prov.get("status") == "NOT_APPLICABLE" and not prov.get("reason"):
                r.err("supply_chain.provenance: NOT_APPLICABLE requires a reason")
        if dep:
            if not isinstance(dep.get("lockfile_required"), bool):
                r.err("supply_chain.dependency_policy.lockfile_required must be true or false")
            check_enum(r, "supply_chain.dependency_policy.vulnerability_gate_severity",
                       dep.get("vulnerability_gate_severity"), LEVELS + ["NONE"])
            if tier in ("MEDIUM", "HIGH", "CRITICAL") and mode != "SPIKE" and dep.get("vulnerability_gate_severity") == "NONE":
                trace(f"supply_chain.dependency_policy.vulnerability_gate_severity must not be NONE at tier {tier}")
        if vm:
            check_enum(r, "supply_chain.vulnerability_management.status", vm.get("status"), CONTROL_STATUS)
            if vm.get("status") == "NOT_APPLICABLE" and not vm.get("reason"):
                r.err("supply_chain.vulnerability_management: NOT_APPLICABLE requires a reason")
            sla = vm.get("remediation_sla_days")
            if sla is not None and not (isinstance(sla, int) and not isinstance(sla, bool) and sla > 0):
                r.err("supply_chain.vulnerability_management.remediation_sla_days must be a positive integer or null")
            if tier in ("MEDIUM", "HIGH", "CRITICAL") and mode != "SPIKE" and vm.get("status") not in ("NOT_APPLICABLE", None):
                if not sla:
                    trace(f"supply_chain.vulnerability_management.remediation_sla_days is required at tier {tier}")
                if not vm.get("disclosure"):
                    trace(f"supply_chain.vulnerability_management.disclosure (how to report a vulnerability) is required at tier {tier}")
                if not vm.get("support_policy"):
                    trace(f"supply_chain.vulnerability_management.support_policy (how long fixes/updates are provided) is required at tier {tier}")

        reg = project.get("regulatory_context")
        if reg is None:
            r.err("project.regulatory_context: required in schema 2.2 (use [] if none)")
            reg = []
        if not isinstance(reg, list):
            r.err("project.regulatory_context must be a list")
            reg = []
        for i, entry in enumerate(reg):
            if not isinstance(entry, dict):
                r.err(f"project.regulatory_context[{i}] must be a mapping")
                continue
            check_enum(r, f"project.regulatory_context[{i}].status", entry.get("status"), REGULATORY_STATUS)
            if not entry.get("regime"):
                r.err(f"project.regulatory_context[{i}].regime: name the law, standard or contract")
            if entry.get("status") == "NOT_APPLICABLE" and not entry.get("notes"):
                r.err(f"project.regulatory_context[{i}]: NOT_APPLICABLE requires notes explaining why")
            if status == "APPROVED" and tier in ("HIGH", "CRITICAL") and entry.get("status") == "NOT_ASSESSED":
                r.err(f"project.regulatory_context[{i}]: NOT_ASSESSED cannot be APPROVED at tier {tier}")
        if tier in ("MEDIUM", "HIGH", "CRITICAL") and mode != "SPIKE" and not reg:
            trace(f"project.regulatory_context must list applicable or assessed regimes at tier {tier} "
                  f"(data protection, payments, sector rules)")


    # ---- schema 2.3: engineering practices, source control, capabilities, AI profile ----
    if v23:
        def practice(where, entry, required_here, what, standards):
            """Validate one declared practice map. Returns True when it is stated or justified."""
            if not isinstance(entry, dict) or not entry:
                if required_here:
                    trace(f"{where}: state {what} [{standards}]")
                return False
            check_enum(r, f"{where}.status", entry.get("status"), CONTROL_STATUS)
            st = entry.get("status")
            if st == "NOT_APPLICABLE":
                if not str(entry.get("reason") or "").strip():
                    r.err(f"{where}: NOT_APPLICABLE requires a reason")
                return True
            if required_here and not str(entry.get("practice") or "").strip():
                trace(f"{where}.practice: state {what} [{standards}]")
                return False
            return True

        tier_rank = TIER_RANK.get(tier, 0)
        enforce = mode != "SPIKE"

        # capabilities
        if not caps:
            r.err("capabilities: required in schema 2.3 (handles_files, uses_self_contained_tokens, uses_federated_login)")
        for flag in CAPABILITY_FLAGS:
            if not isinstance(caps.get(flag), bool):
                r.err(f"capabilities.{flag} must be true or false")

        # engineering practices
        for key, (min_tier, what, standards) in ENGINEERING_PRACTICES.items():
            needed = enforce and tier_rank >= TIER_RANK[min_tier]
            practice(f"engineering.{key}", eng.get(key), needed, what, standards)
        for flag, (what, standards) in CAPABILITY_FLAGS.items():
            key = CAPABILITY_PRACTICE[flag]
            practice(f"engineering.{key}", eng.get(key), enforce and caps.get(flag) is True, what, standards)

        # vulnerability management additions (RV.1 - RV.3)
        vm23 = as_map("supply_chain.vulnerability_management", sc.get("vulnerability_management"))
        if vm23 and vm23.get("status") not in ("NOT_APPLICABLE", None) and enforce:
            if tier_rank >= TIER_RANK["MEDIUM"]:
                if not str(vm23.get("monitoring_sources") or "").strip():
                    trace("supply_chain.vulnerability_management.monitoring_sources: state how new vulnerabilities are found "
                          f"(scanners, advisories, reports) at tier {tier} [SSDF RV.1]")
                if not str(vm23.get("triage_method") or "").strip():
                    trace("supply_chain.vulnerability_management.triage_method: state how findings are prioritised "
                          f"at tier {tier} [SSDF RV.2]")
            if tier_rank >= TIER_RANK["HIGH"] and not str(vm23.get("root_cause_practice") or "").strip():
                trace("supply_chain.vulnerability_management.root_cause_practice: state how root causes are analysed after "
                      f"a vulnerability or incident at tier {tier} [SSDF RV.3]")

        # build hardening evidence (SLSA Build L3)
        prov23 = as_map("supply_chain.provenance", sc.get("provenance"))
        slsa_b = (risk.get("assurance") or {}).get("slsa_build_level") if isinstance(risk.get("assurance"), dict) else None
        if isinstance(slsa_b, int) and not isinstance(slsa_b, bool) and slsa_b >= 3 and enforce:
            bh = as_map("supply_chain.build_hardening", sc.get("build_hardening"))
            if not bh:
                trace("supply_chain.build_hardening: required when slsa_build_level is 3 "
                      "(isolated_builds, signing_keys_isolated, platform)")
            else:
                for field in ("isolated_builds", "signing_keys_isolated"):
                    if bh.get(field) is not True:
                        trace(f"supply_chain.build_hardening.{field} must be true at SLSA build level 3")
                if not str(bh.get("platform") or "").strip():
                    trace("supply_chain.build_hardening.platform: name the build platform")

        # source control (SLSA Source track)
        assurance23 = risk.get("assurance") if isinstance(risk.get("assurance"), dict) else {}
        src_level = assurance23.get("slsa_source_level")
        if not (isinstance(src_level, int) and not isinstance(src_level, bool) and 0 <= src_level <= 4):
            r.err("risk.assurance.slsa_source_level must be an integer 0-4 in schema 2.3")
            src_level = None
        if not src:
            r.err("source: required in schema 2.3 (repository, protected_branch, history_protected, required_checks, review_policy)")
        else:
            check_enum(r, "source.review_policy", src.get("review_policy"), REVIEW_POLICIES)
            if src_level is not None:
                if enforce and tier in MIN_SLSA_SOURCE and src_level < MIN_SLSA_SOURCE[tier]:
                    deviation = assurance23.get("deviation_reason")
                    (r.warn if deviation else r.err)(
                        f"risk.assurance.slsa_source_level {src_level} is below the policy minimum {MIN_SLSA_SOURCE[tier]} for tier {tier}"
                        + (" (deviation_reason recorded)" if deviation else " (add deviation_reason to accept the gap)"))
                if src_level >= 1 and not str(src.get("repository") or "").strip():
                    trace("source.repository: name the version-controlled repository (Source L1)")
                if src_level >= 2 and (src.get("protected_branch") is not True or src.get("history_protected") is not True):
                    trace("source.protected_branch and source.history_protected must be true at Source L2 (history and provenance)")
                if src_level >= 3 and not (isinstance(src.get("required_checks"), list) and src["required_checks"]):
                    trace("source.required_checks: list the checks the platform enforces before merge (Source L3)")
                if src_level >= 4 and src.get("review_policy") != "TWO_PARTY":
                    trace("source.review_policy must be TWO_PARTY at Source L4")

        # project gate (project.intake): constraint first, existing assets, a named bet, outcomes, rejected paths
        intake = as_map("project.intake", project.get("intake"))
        def text(v):
            return isinstance(v, str) and bool(v.strip())
        if not intake:
            trace("project.intake: required in schema 2.3 (answer the project gate: constraint, existing_assets, bet, outcomes, rejected_options)")
        else:
            cons = as_map("project.intake.constraint", intake.get("constraint"))
            if not text(cons.get("statement")):
                trace("project.intake.constraint.statement: name the constraint this project relieves (what is in the way of the business outcome), not the thing to be built")
            if tier_rank >= TIER_RANK["MEDIUM"] and enforce:
                if not text(cons.get("evidence")):
                    trace(f"project.intake.constraint.evidence: say what shows this is the constraint (data, incident, customer or contract requirement) at tier {tier}")
                forces = as_map("project.intake.forces", intake.get("forces"))
                for f in INTAKE_FORCES:
                    if not text(forces.get(f)):
                        trace(f"project.intake.forces.{f}: state the {f} force on the people this serves (push of current pain, pull of the solution, anxiety about it, habit of the current way) at tier {tier}")
                ea = intake.get("existing_assets")
                if not (isinstance(ea, list) and ea and all(text(x) for x in ea)):
                    trace(f"project.intake.existing_assets: list what already works or already exists (platform features, prior controls, current tools) before adding new, at tier {tier}; use [\"none\"] if truly nothing")
                bet = as_map("project.intake.bet", intake.get("bet"))
                for f in ("belief", "wager", "expected_outcome", "leading_indicator"):
                    if not text(bet.get(f)):
                        trace(f"project.intake.bet.{f}: complete 'We believe <belief>, so we are betting <wager> that this produces <expected_outcome>; we will watch <leading_indicator>' at tier {tier}")
                outs = intake.get("outcomes")
                if not (isinstance(outs, list) and outs and all(text(x) for x in outs)):
                    trace(f"project.intake.outcomes: list at least one outcome (what changes for the business or users), not activities, at tier {tier}")
                rej = intake.get("rejected_options")
                if not (isinstance(rej, list) and rej):
                    trace(f"project.intake.rejected_options: record at least one path considered and why it was rejected, so the design is one recommendation and not a menu, at tier {tier}")
                else:
                    for i, o in enumerate(rej):
                        if not (isinstance(o, dict) and text(o.get("option")) and text(o.get("reason"))):
                            trace(f"project.intake.rejected_options[{i}]: needs option and reason")

        # executive sponsor (CISA: lead from the top)
        if enforce and tier_rank >= TIER_RANK["HIGH"] and not str(project.get("executive_sponsor") or "").strip():
            trace(f"project.executive_sponsor: name the person at leadership level accountable for this product's security at tier {tier}")

        # AI profile
        if ai_present:
            if not aip:
                trace("ai_profile: required when a component has ai_capability or an AGENT identity exists "
                      "(one practice per key in ai-agent-controls.md)")
            for key, what in AI_PRACTICES.items():
                entry = aip.get(key)
                ok = practice(f"ai_profile.{key}", entry, True, what, "OWASP LLM/ASI")
                if ok and key == "prompt_confidentiality" and isinstance(entry, dict) and entry.get("status") != "NOT_APPLICABLE":
                    if entry.get("secrets_in_prompts") is not False:
                        trace("ai_profile.prompt_confidentiality.secrets_in_prompts must be false (secrets and authorization "
                              "logic never belong in prompts)")
        elif aip:
            r.warn("ai_profile is present but no component has ai_capability and no AGENT identity exists")

    # ---- lifecycle <-> approval ----
    decision = appr.get("decision")
    check_enum(r, "approval.decision", decision, APPROVAL_DECISION)
    if status in LIFECYCLE_TO_DECISION and decision != LIFECYCLE_TO_DECISION[status]:
        r.err(f"lifecycle {status} requires approval.decision {LIFECYCLE_TO_DECISION[status]} (found {decision})")

    digest = design_digest(doc)
    if status == "APPROVED":
        for f in ("approver", "approved_at", "baseline_version", "record_digest"):
            if not appr.get(f):
                r.err(f"APPROVED requires approval.{f}")
        if not appr.get("scope"):
            r.err("APPROVED requires a non-empty approval.scope")
        if appr.get("baseline_version") and appr.get("baseline_version") != doc.get("baseline_version"):
            r.err("approval.baseline_version does not match baseline_version: the approved design changed")
        for label, val in (("record_digest", doc.get("record_digest")), ("approval.record_digest", appr.get("record_digest"))):
            if val and val != digest:
                r.err(f"{label} does not match the computed design digest: the design (or approval scope) changed after approval")
        if blocking_decisions:
            r.err(f"APPROVED with approval-blocking decisions open: {', '.join(blocking_decisions)}")
        if blocking_unresolved:
            r.err(f"APPROVED with approval-blocking unresolved controls: {', '.join(blocking_unresolved)}")
    if status in ("REJECTED",):
        for f in ("approver", "approved_at"):
            if not appr.get(f):
                r.err(f"REJECTED requires approval.{f} (who decided, when)")
    if status == "SUSPENDED" and not any(c.get("material") for c in colls["changes"]):
        r.err("SUSPENDED requires at least one material CHG-* record explaining why")
    for f in ("approved_at", "expires_at"):
        try:
            parse_date(appr.get(f))
        except (ValueError, TypeError):
            r.err(f"approval.{f} is not ISO 8601")
    for label, val in (("record_digest", doc.get("record_digest")), ("approval.record_digest", appr.get("record_digest"))):
        if val is not None and not HEX64.match(str(val)):
            r.err(f"{label} must be a 64-char lowercase SHA-256 hex digest or null")

    ctx = {
        "digest": digest, "status": status, "approval": appr, "lifecycle": lc, "mode": mode,
        "blocking_decisions": blocking_decisions, "blocking_unresolved": blocking_unresolved,
        "gates": gates, "evidence": evidence,
    }
    return r, ctx


def ci_gate(r, ctx, expected_digest, target, stage, allow_self_asserted=False):
    """Fail-closed approval checks for CI. Adds errors to r."""
    if ctx.get("status") != "APPROVED":
        r.err(f"GATE: lifecycle is {ctx.get('status')}, not APPROVED")
        return
    appr, lc = ctx["approval"], ctx["lifecycle"]
    if expected_digest:
        if expected_digest.strip().lower() != ctx["digest"]:
            r.err("GATE: design digest does not match the digest from the protected approval source")
    elif allow_self_asserted:
        r.warn("GATE: --allow-self-asserted: approval metadata in this file is self-asserted (local use only)")
    else:
        r.err("GATE: no --expected-digest from a protected source; refusing to trust approval metadata "
              "stored in the same change (use --allow-self-asserted only for local experiments)")
    try:
        exp = parse_date(appr.get("expires_at"))
        if exp and exp < now_utc():
            r.err("GATE: approval has expired")
    except (ValueError, TypeError):
        r.err("GATE: approval.expires_at is not ISO 8601")
    tokens = _scope_tokens(appr.get("scope"))
    if ctx.get("mode") == "SPIKE" and target != "development":
        r.err(f"GATE: a SPIKE can only target development, not {target}")
    if target == "production":
        if lc.get("production_posture") == "PRODUCTION_PROHIBITED":
            r.err("GATE: baseline is PRODUCTION_PROHIBITED")
        if lc.get("deployment_lock") != "UNLOCKED":
            r.err("GATE: deployment_lock is not UNLOCKED")
        if "production" not in tokens:
            r.err("GATE: approval.scope does not explicitly name production")
    elif target == "staging":
        if lc.get("development_lock") == "LOCKED":
            r.err("GATE: development_lock is LOCKED")
        if "staging" not in tokens:
            r.err("GATE: approval.scope does not explicitly name staging")
    else:
        if lc.get("development_lock") == "LOCKED":
            r.err("GATE: development_lock is LOCKED")
        if not ({"development", "sandbox"} & set(tokens)):
            r.err("GATE: approval.scope does not name development (or sandbox for a SPIKE)")
    if stage:
        limit = STAGES.index(stage)
        for g in ctx["gates"]:
            if not active(g) or not g.get("blocking") or g.get("stage") not in STAGES:
                continue
            if STAGES.index(g["stage"]) > limit:
                continue
            missing = [e for e in g.get("required_evidence") or [] if ctx["evidence"].get(e, {}).get("status") != "PASSED"]
            if missing:
                r.err(f"GATE: {g.get('id')} ({g['stage']}) lacks PASSED evidence: {', '.join(missing)}")


def main(argv=None):
    ap = argparse.ArgumentParser(description="Validate an Opskeep Elliot Design security-baseline.yaml")
    ap.add_argument("file")
    ap.add_argument("--print-digest", action="store_true", help="print the design digest and exit")
    ap.add_argument("--require-approved", action="store_true", help="CI mode: fail closed unless approved")
    ap.add_argument("--expected-digest", default=os.environ.get("EXPECTED_BASELINE_DIGEST"),
                    help="digest from an independently protected approval source (required with --require-approved)")
    ap.add_argument("--allow-self-asserted", action="store_true",
                    help="local experiments only: trust approval metadata stored in the file")
    ap.add_argument("--target", choices=["development", "staging", "production"], default="development")
    ap.add_argument("--stage", choices=STAGES, help="require PASSED evidence for blocking gates up to this stage")
    ap.add_argument("--strict-warnings", action="store_true", help="treat warnings as errors")
    args = ap.parse_args(argv)

    try:
        with open(args.file, encoding="utf-8") as fh:
            raw = fh.read()
        doc = yaml.safe_load(raw)
    except (OSError, yaml.YAMLError) as exc:
        print(f"ERROR: cannot read {args.file}: {exc}", file=sys.stderr)
        return 2

    try:
        if args.print_digest:
            if not isinstance(doc, dict):
                print("ERROR: document root must be a mapping", file=sys.stderr)
                return 2
            print(design_digest(doc))
            return 0

        report, ctx = validate(doc, raw)
        if args.require_approved:
            ci_gate(report, ctx, args.expected_digest, args.target, args.stage, args.allow_self_asserted)
    except Exception as exc:  # fail closed with a clean message, never a traceback
        print(f"ERROR: validator could not process {args.file} ({type(exc).__name__}: {exc}); treat as failed", file=sys.stderr)
        return 2

    for w in report.warnings:
        print(f"WARNING: {w}")
    for e in report.errors:
        print(f"ERROR: {e}")
    failed = bool(report.errors) or (args.strict_warnings and report.warnings)
    mode = "gate" if args.require_approved else "lint"
    print(f"{'FAIL' if failed else 'PASS'} ({mode}): {len(report.errors)} error(s), {len(report.warnings)} warning(s)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
