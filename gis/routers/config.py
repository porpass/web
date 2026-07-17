import os
from pathlib import Path

import yaml
from fastapi import APIRouter, HTTPException

router = APIRouter()

CONFIG_DIR = Path(os.environ.get("CONFIG_DIR", "config"))


@router.get("/config/instruments")
def get_instruments():
    """Return the full instruments.yaml as JSON."""
    try:
        with open(CONFIG_DIR / "instruments.yaml") as f:
            return yaml.safe_load(f)
    except FileNotFoundError:
        raise HTTPException(status_code=500, detail="instruments.yaml not found")


@router.get("/config/basemaps")
def get_basemaps():
    """Return the full basemaps.yaml as JSON."""
    try:
        with open(CONFIG_DIR / "basemaps.yaml") as f:
            return yaml.safe_load(f)
    except FileNotFoundError:
        raise HTTPException(status_code=500, detail="basemaps.yaml not found")
