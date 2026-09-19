#!/usr/bin/env python3

"""
AnthoGuard Agent
----------------
Lightweight Linux system monitoring agent.

Collects:
- Device identity
- Operating system information
- CPU information
- Memory information
- Disk information
- Network information
- Collection timestamp

Designed as the foundation for the AnthoGuard monitoring platform.
"""

from __future__ import annotations

import json
import platform
import socket
import shutil
import time
from datetime import datetime, timezone
from pathlib import Path


APP_NAME = "AnthoGuard Agent"
APP_VERSION = "0.1.0"


def get_memory_info() -> dict:
    """Read memory information from /proc/meminfo."""
    memory = {}

    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as file:
            for line in file:
                key, value = line.split(":", 1)
                memory[key] = value.strip()
    except (OSError, ValueError):
        return {}

    return {
        "total_kb": _memory_value(memory.get("MemTotal")),
        "available_kb": _memory_value(memory.get("MemAvailable")),
        "free_kb": _memory_value(memory.get("MemFree")),
    }


def _memory_value(value: str | None) -> int | None:
    """Extract the numeric value from /proc/meminfo."""
    if not value:
        return None

    try:
        return int(value.split()[0])
    except (ValueError, IndexError):
        return None


def get_disk_info() -> dict:
    """Return information about the root filesystem."""
    try:
        usage = shutil.disk_usage("/")
        return {
            "total_bytes": usage.total,
            "used_bytes": usage.used,
            "free_bytes": usage.free,
            "usage_percent": round((usage.used / usage.total) * 100, 2),
        }
    except OSError:
        return {}


def get_network_info() -> dict:
    """Collect basic local network identity information."""
    hostname = socket.gethostname()

    try:
        addresses = socket.gethostbyname_ex(hostname)[2]
    except socket.gaierror:
        addresses = []

    return {
        "hostname": hostname,
        "ip_addresses": sorted(set(addresses)),
    }


def collect_system_info() -> dict:
    """Collect the current device information."""
    return {
        "agent": {
            "name": APP_NAME,
            "version": APP_VERSION,
        },
        "device": {
            "hostname": socket.gethostname(),
            "machine": platform.machine(),
            "processor": platform.processor(),
            "system": platform.system(),
            "release": platform.release(),
            "architecture": platform.architecture()[0],
        },
        "cpu": {
            "logical_processors": __import__("os").cpu_count(),
        },
        "memory": get_memory_info(),
        "disk": get_disk_info(),
        "network": get_network_info(),
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


def main() -> None:
    """Run the AnthoGuard agent."""
    print(f"{APP_NAME} v{APP_VERSION}")
    print("=" * 40)

    system_info = collect_system_info()

    print(json.dumps(system_info, indent=2))


if __name__ == "__main__":
    main()

