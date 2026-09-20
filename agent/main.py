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

The agent can register the device with the AnthoGuard API.
"""

from __future__ import annotations

import json
import os
import platform
import socket
import shutil
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib import error, request


APP_NAME = "AnthoGuard Agent"
APP_VERSION = "0.3.0"
API_URL = os.environ.get(
    "ANTHOGUARD_API_URL",
    "http://127.0.0.1:8080/?action=heartbeat",
)

STATE_DIR = Path.home() / ".local" / "share" / "anthoguard"
DEVICE_UUID_FILE = STATE_DIR / "device_uuid"


def get_device_uuid() -> str:
    """Return a persistent UUID for this device."""
    try:
        STATE_DIR.mkdir(parents=True, exist_ok=True)

        if DEVICE_UUID_FILE.exists():
            saved_uuid = DEVICE_UUID_FILE.read_text(
                encoding="utf-8"
            ).strip()

            uuid.UUID(saved_uuid)

            return saved_uuid

        device_uuid = str(uuid.uuid4())

        DEVICE_UUID_FILE.write_text(
            device_uuid,
            encoding="utf-8",
        )

        return device_uuid

    except (OSError, ValueError):
        # If persistent storage is unavailable, use a temporary UUID.
        return str(uuid.uuid4())


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
            "usage_percent": round(
                (usage.used / usage.total) * 100,
                2,
            ),
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


def get_primary_ip() -> str | None:
    """Return the first available local IP address."""
    addresses = get_network_info()["ip_addresses"]

    if addresses:
        return addresses[0]

    return None


def collect_system_info() -> dict:
    """Collect the current device information."""
    return {
        "device_uuid": get_device_uuid(),
        "device_name": socket.gethostname(),

        "hostname": socket.gethostname(),

        "operating_system": platform.system(),
        "os_release": platform.release(),

        "architecture": platform.architecture()[0],
        "processor": platform.processor(),

        "logical_processors": os.cpu_count(),

        "ip_address": get_primary_ip(),

        "memory": get_memory_info(),
        "disk": get_disk_info(),

        "network": get_network_info(),

        "timestamp": datetime.now(
            timezone.utc
        ).isoformat(),
    }


def register_device(system_info: dict) -> dict:
    """Register this device with the AnthoGuard API."""
    payload = json.dumps(system_info).encode("utf-8")

    request_object = request.Request(
        API_URL,
        data=payload,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": f"{APP_NAME}/{APP_VERSION}",
        },
        method="POST",
    )

    try:
        with request.urlopen(
            request_object,
            timeout=10,
        ) as response:

            response_body = response.read().decode("utf-8")

            return json.loads(response_body)

    except error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")

        return {
            "success": False,
            "error": f"API returned HTTP {exc.code}",
            "response": body,
        }

    except error.URLError as exc:
        return {
            "success": False,
            "error": f"Unable to reach API: {exc.reason}",
        }

    except TimeoutError:
        return {
            "success": False,
            "error": "API request timed out",
        }

    except json.JSONDecodeError:
        return {
            "success": False,
            "error": "API returned invalid JSON",
        }


def main() -> None:
    """Run the AnthoGuard agent."""
    print(f"{APP_NAME} v{APP_VERSION}")
    print("=" * 40)

    system_info = collect_system_info()

    print("\nDevice information:")
    print(
        json.dumps(
            system_info,
            indent=2,
        )
    )

    print("\nSending heartbeat...")
    result = register_device(system_info)

    print(
        json.dumps(
            result,
            indent=2,
        )
    )

    if not result.get("success"):
        sys.exit(1)


if __name__ == "__main__":
    main()
