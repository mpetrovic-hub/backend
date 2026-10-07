#!/usr/bin/env python3
"""A fixed 1,004-page synthetic database; contains no archive/customer data."""
from pathlib import Path
import sqlite3

path = Path(__file__).parent / "fixtures" / "kiwi_retention_archive_2000.sqlite"
path.parent.mkdir(exist_ok=True)
if path.exists():
    raise SystemExit("Fixture already exists; verify/reuse the release artifact")
database = sqlite3.connect(path)
database.execute("PRAGMA page_size=4096")
database.execute("PRAGMA journal_mode=DELETE")
database.execute("CREATE TABLE probe (id INTEGER PRIMARY KEY, payload BLOB NOT NULL)")
database.executemany("INSERT INTO probe VALUES (?, ?)", ((i, bytes([i % 251]) * 4000) for i in range(1, 1001)))
database.commit()
database.execute("VACUUM")
pages = database.execute("PRAGMA page_count").fetchone()[0]
assert pages == 1004, pages
assert database.execute("PRAGMA integrity_check").fetchall() == [("ok",)]
database.close()
assert path.stat().st_size == 4112384
print("Created 1,004-page synthetic fixture")
