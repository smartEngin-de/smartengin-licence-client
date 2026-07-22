"""
se_licence – Lizenz- und Auto-Update-Client fuer smartEngin Licence & buy.

Python-Spiegel der .NET-Referenzbibliothek `SmartEngin.Licence`. Bewusst ohne
Fremd-Abhaengigkeiten (nur Standardbibliothek), damit das PyInstaller-Bundle
schlank bleibt. Redet ueber die oeffentliche REST-API `sels/v1` mit dem Server.

Plattformuebergreifend (Windows / macOS / Linux): Datenordner, Prozess-Warten und
das Ausfuehrbar-Bit werden je Betriebssystem passend gewaehlt. Der Selbst-Update-
Tausch ersetzt EINE ausfuehrbare Datei (PyInstaller --onefile); ein macOS-.app-
Bundle oder ein Ordner-Build muss stattdessen ueber einen Installer aktualisiert
werden (siehe python-software-guide.md).

Drei Bausteine, identisch zur .NET-Vorlage:
  * Geraete-ID   – stabile, anonyme Kennung dieser Installation (instance_type=device).
  * LicenceClient – activate / validate / deactivate, Validierung faellt „fail-open"
                    aus (bei Netzfehler zaehlt der letzte bekannte Status → nie sperren).
  * Updater       – /update pruefen, Paket laden, SHA-256 pruefen, laufende .exe
                    per Austausch-Helfer ersetzen und neu starten (--onefile-tauglich).

Kein-Bricking ist Prinzip: nur ein bestaetigt zurueckerstatteter/deaktivierter
Schluessel schaltet Pro-Funktionen ab; ein Serverausfall tut das nie.
"""
import os
import sys
import json
import time
import uuid
import socket
import hashlib
import tempfile
import subprocess
import urllib.parse
import urllib.request

# ---------------------------------------------------------------------------
# Ablageorte (pro Produkt-Slug), spiegelt LicencePaths der .NET-Lib.
# ---------------------------------------------------------------------------

_STATUS_FILE = "status.json"
_INSTANCE_FILE = "instance.id"
_KEY_FILE = "key.txt"


def _data_root() -> str:
    """Plattform-ueblicher Ort fuer App-Daten (Windows / macOS / Linux)."""
    if os.name == "nt":
        return os.environ.get("LOCALAPPDATA") or os.path.join(
            os.path.expanduser("~"), "AppData", "Local")
    if sys.platform == "darwin":
        return os.path.join(os.path.expanduser("~"), "Library", "Application Support")
    # Linux / sonstige POSIX: XDG-Basisverzeichnis.
    return os.environ.get("XDG_DATA_HOME") or os.path.join(
        os.path.expanduser("~"), ".local", "share")


def _sanitize(name: str) -> str:
    safe = "".join(c if c.isalnum() or c in ("-", "_", ".") else "_" for c in (name or ""))
    return safe or "product"


def data_dir(slug: str) -> str:
    """Ordner fuer den Client-Zustand dieses Produkts (Geraete-ID, Cache, Schluessel)."""
    return os.path.join(_data_root(), "SmartEnginLicence", _sanitize(slug))


def machine_id(slug: str) -> str:
    """
    Stabile, anonyme Geraete-ID. Einmalig eine zufaellige GUID im Datenordner
    ablegen und zusammen mit dem Rechnernamen zu einem 64-stelligen Hex-String
    hashen. Es verlaesst nie ein personenbezogenes Datum die Maschine – das ist
    exakt der `instance`-Wert, den der Server speichert.
    """
    d = data_dir(slug)
    try:
        os.makedirs(d, exist_ok=True)
    except OSError:
        pass

    path = os.path.join(d, _INSTANCE_FILE)
    guid = ""
    if os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                guid = f.read().strip()
        except OSError:
            guid = ""
    if not guid:
        guid = uuid.uuid4().hex
        try:
            with open(path, "w", encoding="utf-8") as f:
                f.write(guid)
        except OSError:
            pass  # best effort

    hostname = os.environ.get("COMPUTERNAME") or socket.gethostname() or "unknown"
    raw = (guid + "|" + hostname).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


# ---------------------------------------------------------------------------
# Schluessel- und Status-Ablage (fail-open-Cache).
# ---------------------------------------------------------------------------

def save_key(slug: str, key: str) -> None:
    """Lizenzschluessel nach der ersten Aktivierung lokal merken."""
    d = data_dir(slug)
    try:
        os.makedirs(d, exist_ok=True)
        with open(os.path.join(d, _KEY_FILE), "w", encoding="utf-8") as f:
            f.write((key or "").strip())
    except OSError:
        pass


def load_key(slug: str) -> str:
    try:
        with open(os.path.join(data_dir(slug), _KEY_FILE), "r", encoding="utf-8") as f:
            return f.read().strip()
    except OSError:
        return ""


def is_licensed(slug: str) -> bool:
    """
    True, sobald einmal erfolgreich aktiviert wurde (Schluessel liegt lokal).
    Bewusst nur der gespeicherte Schluessel – danach gilt fail-open, ein
    Serverausfall darf die App nie sperren.
    """
    return bool(load_key(slug))


def _save_status(slug: str, status: dict) -> None:
    try:
        d = data_dir(slug)
        os.makedirs(d, exist_ok=True)
        with open(os.path.join(d, _STATUS_FILE), "w", encoding="utf-8") as f:
            json.dump(status, f)
    except OSError:
        pass


def _load_status(slug: str):
    try:
        with open(os.path.join(data_dir(slug), _STATUS_FILE), "r", encoding="utf-8") as f:
            s = json.load(f)
        s["from_cache"] = True
        return s
    except (OSError, json.JSONDecodeError):
        return None


# ---------------------------------------------------------------------------
# Optionen + duenne HTTP-Schicht ueber sels/v1.
# ---------------------------------------------------------------------------

class LicenceOptions:
    def __init__(self, server_url, product_slug, app_version,
                 instance_type="device", label=None, executable_path=None):
        self.server_url = server_url
        self.product_slug = product_slug
        self.app_version = app_version
        self.instance_type = instance_type
        self.label = label
        self.executable_path = executable_path

    @property
    def api_base(self) -> str:
        return self.server_url.rstrip("/") + "/wp-json/sels/v1"


_TIMEOUT = 20


def _post(url: str, fields: dict) -> dict:
    data = urllib.parse.urlencode(fields).encode("utf-8")
    req = urllib.request.Request(url, data=data, method="POST")
    with urllib.request.urlopen(req, timeout=_TIMEOUT) as resp:
        return json.loads(resp.read().decode("utf-8"))


def _get(url: str) -> dict:
    req = urllib.request.Request(url, method="GET")
    with urllib.request.urlopen(req, timeout=_TIMEOUT) as resp:
        return json.loads(resp.read().decode("utf-8"))


def _features_enabled(state: str) -> bool:
    """Fail-open: nur bestaetigt zurueckerstattet/deaktiviert schaltet ab."""
    return state not in ("refunded", "disabled")


def _parse_status(root: dict) -> dict:
    state = str(root.get("status") or "unknown")
    if state not in ("active", "expired", "refunded", "disabled"):
        state = "unknown"
    # /validate liefert ein explizites "valid"; /activate leitet es aus dem Status ab.
    if "valid" in root:
        valid = bool(root.get("valid"))
    else:
        valid = state == "active"
    return {
        "valid": valid,
        "state": state,
        "valid_until": root.get("valid_until") or None,
        "activations_left": root.get("activations_left"),
        "checked_at": time.time(),
        "from_cache": False,
        "features_enabled": _features_enabled(state),
    }


class LicenceClient:
    """activate / validate / deactivate. Validierung faellt fail-open aus."""

    def __init__(self, options: LicenceOptions):
        self.o = options
        self.instance = machine_id(options.product_slug)

    def activate(self, key: str) -> dict:
        """Geraet gegen einen Schluessel registrieren. Serverseitig idempotent."""
        try:
            fields = {
                "key": key,
                "product": self.o.product_slug,
                "instance": self.instance,
                "instance_type": self.o.instance_type,
            }
            if self.o.label:
                fields["label"] = self.o.label

            root = _post(self.o.api_base + "/activate", fields)
            if root.get("success") is True:
                status = _parse_status(root)
                _save_status(self.o.product_slug, status)
                return {"success": True, "status": status}
            return {
                "success": False,
                "error": str(root.get("error") or ""),
                "message": str(root.get("message") or ""),
            }
        except Exception as ex:  # noqa: BLE001 – Netzfehler sauber melden
            return {"success": False, "error": "network_error", "message": str(ex)}

    def validate(self, key: str) -> dict:
        """
        Lizenz neu pruefen. Bei Netzfehler den gecachten Status liefern (oder einen
        erlaubenden Standard) – niemals eine Ausnahme werfen.
        """
        try:
            fields = {
                "key": key,
                "product": self.o.product_slug,
                "instance": self.instance,
                "instance_type": self.o.instance_type,
            }
            root = _post(self.o.api_base + "/validate", fields)
            status = _parse_status(root)
            _save_status(self.o.product_slug, status)
            return status
        except Exception:  # noqa: BLE001
            cached = _load_status(self.o.product_slug)
            if cached is not None:
                cached["features_enabled"] = _features_enabled(cached.get("state", "unknown"))
                return cached
            return {
                "valid": True,
                "state": "unknown",
                "valid_until": None,
                "activations_left": None,
                "checked_at": time.time(),
                "from_cache": True,
                "features_enabled": True,
            }

    def deactivate(self, key: str) -> None:
        """Aktivierungs-Slot dieses Geraets freigeben. Best-effort (wirft nie)."""
        try:
            _post(self.o.api_base + "/deactivate", {
                "key": key,
                "instance": self.instance,
                "instance_type": self.o.instance_type,
            })
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# Updater – stilles Selbst-Update im WordPress-Stil.
# ---------------------------------------------------------------------------

_APPLY_FLAG = "--se-apply-update"


def _creationflags() -> int:
    """Unter Windows Subprozesse ohne aufblitzendes Konsolenfenster starten."""
    flags = 0
    if os.name == "nt":
        flags |= getattr(subprocess, "CREATE_NO_WINDOW", 0)
        flags |= getattr(subprocess, "DETACHED_PROCESS", 0)
    return flags


def _sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def _executable_path(options: LicenceOptions) -> str:
    """Pfad der zu ersetzenden .exe. Bei der gebauten --onefile-App = sys.executable."""
    if options.executable_path:
        return options.executable_path
    if getattr(sys, "frozen", False):
        return sys.executable
    return os.path.abspath(sys.argv[0])


class Updater:
    """
    /update pruefen, Paket laden, SHA-256 pruefen, laufende .exe tauschen und neu starten.

    Eine laufende --onefile-.exe sperrt sich selbst und kann ihre eigene Datei nicht
    ueberschreiben. Der Tausch laeuft daher ueber einen kleinen Helfer: eine Kopie der
    NEUEN exe wird mit `--se-apply-update` gestartet, wartet auf das Ende dieses
    Prozesses, ersetzt die alte Datei und startet sie neu. `try_run_updater_mode`
    gehoert an die ERSTE Stelle von main(), damit dieser Helfer-Aufruf vor dem
    App-Start abgefangen wird.
    """

    def __init__(self, options: LicenceOptions):
        self.o = options
        self.instance = machine_id(options.product_slug)

    def check_for_update(self, key: str):
        """
        Server nach einer neueren Version fragen. None, wenn aktuell, bei
        ungueltiger/abgelaufener Lizenz oder bei jedem Netzfehler (fail-open).
        """
        try:
            q = self.o.api_base + "/update?" + urllib.parse.urlencode({
                "key": key,
                "product": self.o.product_slug,
                "version": self.o.app_version,
                "instance": self.instance,
                "instance_type": self.o.instance_type,
            })
            root = _get(q)
            if root.get("update") is not True:
                return None
            return {
                "new_version": str(root.get("new_version") or ""),
                "package": str(root.get("package") or ""),
                "sha256": str(root.get("sha256") or ""),
                "filename": str(root.get("filename") or ""),
                "platform": str(root.get("platform") or ""),
                "changelog_url": str(root.get("changelog_url") or ""),
            }
        except Exception:  # noqa: BLE001
            return None

    def download_and_verify(self, info: dict) -> str:
        """Paket in einen Staging-Ordner laden und SHA-256 pruefen. Wirft bei Abweichung."""
        pkg_url = info.get("package") or ""
        if not pkg_url:
            raise ValueError("Das Update hat keine Paket-URL.")

        d = os.path.join(tempfile.gettempdir(), "SmartEnginUpdate", _sanitize(self.o.product_slug))
        os.makedirs(d, exist_ok=True)

        # Server-Dateinamen gegen Pfad-Tricks absichern.
        name = os.path.basename(info.get("filename") or "") or "payload.bin"
        target = os.path.join(d, name)

        req = urllib.request.Request(pkg_url, method="GET")
        with urllib.request.urlopen(req, timeout=_TIMEOUT * 6) as resp, open(target, "wb") as fs:
            while True:
                chunk = resp.read(65536)
                if not chunk:
                    break
                fs.write(chunk)

        expected = (info.get("sha256") or "").strip().lower()
        if expected:
            actual = _sha256_file(target)
            if actual != expected:
                try:
                    os.remove(target)
                except OSError:
                    pass
                raise ValueError("Pruefsumme stimmt nicht: erwartet %s, erhalten %s." % (expected, actual))
        return target

    def apply_update_and_restart(self, verified_package_path: str, relaunch: bool = True) -> None:
        """
        Laufende .exe durch das gepruefte Paket ersetzen und neu starten. Fuer eine
        --onefile-.exe wird der Tausch-Helfer gestartet und die App beendet. Eine
        .msi wird stattdessen dem Windows-Installer uebergeben. Kehrt nicht zurueck.
        """
        target = _executable_path(self.o)
        ext = os.path.splitext(verified_package_path)[1].lower()

        if ext == ".msi":
            subprocess.Popen(["msiexec", "/i", verified_package_path, "/qb"],
                              creationflags=_creationflags())
            os._exit(0)

        # Neue exe an einen Helfer-Pfad kopieren, der NICHT die zu ersetzende Datei
        # ist, damit er waehrend des Ueberschreibens laufen kann.
        helper = os.path.join(os.path.dirname(verified_package_path),
                              "se-apply.exe" if os.name == "nt" else "se-apply")
        _copy_file(verified_package_path, helper)
        _make_executable(helper)

        args = [
            helper, _APPLY_FLAG,
            "--target", target,
            "--source", verified_package_path,
            "--pid", str(os.getpid()),
        ]
        if relaunch:
            args.append("--relaunch")

        subprocess.Popen(args, creationflags=_creationflags(), close_fds=True)
        os._exit(0)

    # -- Helfer-Modus -------------------------------------------------------

    @staticmethod
    def try_run_updater_mode(argv) -> bool:
        """
        Den Helfer-Aufruf abfangen. Als ERSTE Anweisung in main() aufrufen: Wurde der
        Prozess mit `--se-apply-update` gestartet, wartet er auf das Ende der alten
        App, ersetzt deren Datei, startet sie neu, beendet sich und liefert True.
        Sonst False – dann laeuft die App normal weiter.
        """
        if _APPLY_FLAG not in (argv or []):
            return False

        target = _arg_val(argv, "--target")
        source = _arg_val(argv, "--source")
        relaunch = "--relaunch" in argv
        try:
            pid = int(_arg_val(argv, "--pid") or "0")
        except ValueError:
            pid = 0

        apply_swap(target, source, pid, relaunch)
        os._exit(0)


def _copy_file(src: str, dst: str) -> None:
    with open(src, "rb") as fi, open(dst, "wb") as fo:
        while True:
            chunk = fi.read(1024 * 1024)
            if not chunk:
                break
            fo.write(chunk)


def _make_executable(path: str) -> None:
    """Auf POSIX (macOS/Linux) das Ausfuehrbar-Bit setzen; unter Windows unnoetig."""
    if os.name == "nt":
        return
    try:
        import stat
        mode = os.stat(path).st_mode
        os.chmod(path, mode | stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH)
    except OSError:
        pass


def _wait_for_pid_exit(pid: int, timeout_s: float) -> None:
    """Auf das Ende des alten Prozesses warten, damit dessen Datei entsperrt."""
    if pid <= 0:
        return
    if os.name == "nt":
        try:
            import ctypes
            SYNCHRONIZE = 0x00100000
            handle = ctypes.windll.kernel32.OpenProcess(SYNCHRONIZE, False, pid)
            if handle:
                ctypes.windll.kernel32.WaitForSingleObject(handle, int(timeout_s * 1000))
                ctypes.windll.kernel32.CloseHandle(handle)
                return
        except Exception:  # noqa: BLE001
            pass
        # Windows-Fallback: rein zeitbasiert warten.
        time.sleep(min(timeout_s, 5))
        return
    # POSIX (macOS/Linux): pollen, bis der Prozess wirklich weg ist.
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        try:
            os.kill(pid, 0)
        except OSError:
            return  # Prozess ist beendet -> Datei ist frei
        time.sleep(0.25)


def apply_swap(target: str, source: str, pid: int, relaunch: bool) -> None:
    """
    Der eigentliche Datei-Tausch, aus try_run_updater_mode ausgelagert, damit er ohne
    Prozess-Ende testbar bleibt: auf das Ende der alten App warten, Ziel durch die
    Quelle ersetzen (mit Wiederholungen, bis die Sperre faellt), optional neu starten,
    aufraeumen.
    """
    _wait_for_pid_exit(pid, 20)

    for _ in range(20):
        try:
            _copy_file(source, target)
            _make_executable(target)  # POSIX: ersetzte Binaerdatei bleibt ausfuehrbar
            break
        except OSError:
            time.sleep(0.25)

    if relaunch:
        try:
            subprocess.Popen([target], creationflags=_creationflags(), close_fds=True)
        except OSError:
            pass

    try:
        os.remove(source)
    except OSError:
        pass


def _arg_val(argv, name: str) -> str:
    try:
        i = argv.index(name)
    except ValueError:
        return ""
    return argv[i + 1] if i + 1 < len(argv) else ""
