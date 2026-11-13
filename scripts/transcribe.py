"""Local Whisper bridge. Only JSON results are written to stdout."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys


def select_model(whisper):
    configured = os.environ.get("HQ_WHISPER_MODEL")
    if configured:
        return configured
    # A download in progress must not make recording/transcription unavailable.
    cached = Path(os.environ.get("XDG_CACHE_HOME", Path.home() / ".cache")) / "whisper/small.pt"
    expected = whisper._MODELS["small"].split("/")[-2]
    if cached.is_file():
        checksum = hashlib.sha256()
        with cached.open("rb") as stream:
            for block in iter(lambda: stream.read(1024 * 1024), b""):
                checksum.update(block)
        if checksum.hexdigest() == expected:
            return "small"
    return "base"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("audio", nargs="?")
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--language", choices=["auto", "tl", "en"], default="auto")
    args = parser.parse_args()
    ffmpeg = os.environ.get("HQ_FFMPEG_PATH")
    if ffmpeg:
        os.environ["PATH"] = str(Path(ffmpeg).parent) + os.pathsep + os.environ.get("PATH", "")
    # Apache may have an older PATH than the interactive PowerShell session.
    if not shutil.which("ffmpeg"):
        candidates = [Path("C:/ffmpeg/bin/ffmpeg.exe"), Path.home() / "scoop/apps/ffmpeg/current/bin/ffmpeg.exe"]
        for candidate in candidates:
            if candidate.is_file():
                os.environ["PATH"] = str(candidate.parent) + os.pathsep + os.environ.get("PATH", "")
                break
    if not shutil.which("ffmpeg"):
        raise RuntimeError("FFmpeg is unavailable. Install it and restart Apache, or set HQ_FFMPEG_PATH to ffmpeg.exe.")
    import whisper
    model_name = select_model(whisper)
    if args.check:
        return {"ok": True, "ffmpeg": shutil.which("ffmpeg"), "model": model_name, "languages": ["auto", "tl", "en"]}
    if not args.audio or not Path(args.audio).is_file():
        raise RuntimeError("The consultation recording was not found.")
    if model_name.endswith(".en"):
        raise RuntimeError("Use a multilingual Whisper model, not an English-only .en model.")
    model = whisper.load_model(model_name)
    result = model.transcribe(
        args.audio, task="transcribe", fp16=False, verbose=None,
        language=None if args.language == "auto" else args.language,
        beam_size=5, best_of=5, temperature=(0.0, 0.2, 0.4),
        condition_on_previous_text=True,
    )
    text = result["text"].strip()
    if not text:
        raise RuntimeError("No speech was detected. Try a clearer recording.")
    return {"ok": True, "text": text, "language": result.get("language"), "model": model_name}


if __name__ == "__main__":
    try:
        print(json.dumps(main(), ensure_ascii=True))
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        sys.exit(1)
