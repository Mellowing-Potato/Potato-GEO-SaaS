"""Keep independent local report formats and record why an optional export was lost."""
import json
import subprocess
from pathlib import Path


def weasy_pdf(target, **source):
    from weasyprint import HTML

    HTML(**source).write_pdf(str(target))


class OutputFormats:
    def __init__(self, directory):
        self.directory = Path(directory).resolve()
        self.issues = {}

    def record(self, fmt, status, reason):
        self.issues[fmt] = {"status": status, "reason": reason}
        (self.directory / "format-status.json").write_text(
            json.dumps(self.issues, ensure_ascii=False), encoding="utf-8"
        )

    def write(self, fmt, target, writer):
        """Only catch failures inside this format's exporter. Content validation stays outside."""
        target = Path(target)
        if not target.resolve().is_relative_to(self.directory):
            raise ValueError("Report output must stay within its directory")
        try:
            writer()
            if not target.is_file() or target.stat().st_size == 0:
                raise ValueError("No output")
            return True
        except ImportError as exc:
            module = (exc.name or "").split(".")[0]
            reason = {"weasyprint": "pdf_engine", "docx": "python_docx", "reportlab": "reportlab",
                      "markdown": "python_markdown", "bs4": "beautifulsoup", "pypdf": "pdf_reader"}.get(module, "python_dependency")
            self.record(fmt, "unavailable", reason)
        except FileNotFoundError as exc:
            missing = Path(exc.filename or "").name
            if missing in {"pandoc", "pdftoppm", "pyftsubset"}:
                self.record(fmt, "unavailable", missing)
            else:
                self.record(fmt, "failed", "export_failed")
        except OSError as exc:
            native = any(word in str(exc).lower() for word in ("cannot load library", "dlopen", "library not loaded", "dll load"))
            self.record(fmt, "unavailable" if native else "failed", "native_library" if native else "export_failed")
        except (ValueError, RuntimeError, subprocess.SubprocessError):
            self.record(fmt, "failed", "export_failed")
        target.unlink(missing_ok=True)
        return False
