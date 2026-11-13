Local Whisper
=============

The consultation page's Generate transcript button invokes scripts/transcribe.py
through the project's .venv-whisper/Scripts/python.exe. No API key or separate
service is required. Transcription runs during the request, with a ten-minute
timeout. Long recordings may need a background worker in a future deployment.

Install openai-whisper in that environment and install FFmpeg. Restart Apache
after changing PATH. The bridge also detects C:/ffmpeg/bin/ffmpeg.exe.

Optional server environment variables:
- HQ_WHISPER_PYTHON: absolute path to the environment's Python executable
- HQ_WHISPER_MODEL: model name (default: multilingual small)
- HQ_FFMPEG_PATH: absolute path to ffmpeg.exe

Check dependencies:
  .\.venv-whisper\Scripts\python.exe scripts/transcribe.py --check

The first transcription downloads model weights if not already cached for the
account running Apache. That account needs access to the model cache. Transcripts
are saved through the existing AITranscriptVersions workflow. Patient consent is
required. Physicians must review the text. Summary generation remains a placeholder.

Language selection
------------------
The consultation page offers automatic detection, Tagalog (tl), and English
(en). Automatic mode detects a language from the recording; it does not promise
reliable recognition of every language switch. Cebuano/Bisaya is not a dedicated
Whisper language. Do not force it to Tagalog or advertise guaranteed support.
Transcription preserves spoken text rather than translating it to English.
The multilingual small model is selected only after its cached file passes the official SHA-256 checksum. Until then the installed base model stays usable. CPU processing is slower with small.
Override HQ_WHISPER_MODEL only with another multilingual model.

Accuracy checks
---------------
The result reports the actual model and detected/selected language in the UI.
Automatic detection uses the opening audio, not fresh detection at every language
switch. For Tagalog/English code switching, compare Automatic against Tagalog on
the same test recording. Decoding uses beam search, limited temperature fallback,
and preceding text context. These settings do not guarantee better accuracy on
every recording; measure against a human-written reference.
