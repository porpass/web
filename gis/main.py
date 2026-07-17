from dotenv import load_dotenv
from pathlib import Path

load_dotenv(dotenv_path=Path(__file__).parent.parent / ".env")

import os
os.environ.setdefault("CONFIG_DIR", str(Path(__file__).parent / "config"))

from fastapi import FastAPI
from routers import config, vectors

app = FastAPI(title="Porpass Planetary Radar API")

app.include_router(config.router, prefix="/api")
app.include_router(vectors.router, prefix="/api")

@app.get("/api/health")
def health():
    return {"status": "ok"}
