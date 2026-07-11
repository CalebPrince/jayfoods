#!/usr/bin/env python3
"""Unified entry point / orchestrator for Jayfoods.

Responsibilities (per the architecture blueprint):
  1. Verify environment requirements (PHP on PATH).
  2. Run the idempotent database migration (database/migrate.php).
  3. Launch the PHP built-in dev server on port 8010 with public/index.php
     as the front controller.

Usage:  python server.py
"""

import os
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))
PORT = 8010
DOCROOT = os.path.join(ROOT, "public")
ROUTER = os.path.join(DOCROOT, "index.php")
MIGRATE = os.path.join(ROOT, "database", "migrate.php")


def require(binary: str) -> None:
    if shutil.which(binary) is None:
        sys.exit(f"[jayfoods] Required executable '{binary}' was not found on PATH.")


def main() -> None:
    require("php")

    print("[jayfoods] Applying database migration...")
    result = subprocess.run(["php", MIGRATE])
    if result.returncode != 0:
        sys.exit("[jayfoods] Migration failed — aborting startup.")

    url = f"http://localhost:{PORT}"
    print(f"[jayfoods] Serving on {url}")
    print(f"[jayfoods] Order page: {url}/order.html   (Ctrl+C to stop)")

    try:
        subprocess.run(["php", "-S", f"localhost:{PORT}", "-t", DOCROOT, ROUTER])
    except KeyboardInterrupt:
        print("\n[jayfoods] Server stopped.")


if __name__ == "__main__":
    main()
