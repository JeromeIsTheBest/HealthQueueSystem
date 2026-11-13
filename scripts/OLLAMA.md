Local consultation summaries
===========================

Install Ollama for Windows, then run:

    ollama pull qwen2.5:1.5b

Ollama must be running on http://127.0.0.1:11434. If the tray application is
not running, start it from the Start menu, or run `ollama serve` in a terminal.

In an active physician consultation, generate and review the transcript first.
Click Generate summary. Unsaved transcript corrections and clinical notes are
saved before generation. Review the draft before clicking Insert into notes.
Generation is explicit; opening a consultation does not load or call a model.

Server environment variables (restart Apache after changes):
- HQ_SUMMARY_MODEL: installed Ollama model, default qwen2.5:1.5b
- HQ_OLLAMA_URL: local HTTP service URL, default http://127.0.0.1:11434

Only loopback service addresses are accepted. No cloud API key is used. Summary
requests have a five-minute timeout. Inputs above 16,000 characters are rejected
rather than silently truncated. Invalid or incomplete responses are not saved.
The model stays loaded for ten minutes after a request to reduce startup delays.

Test with fictional transcripts. This small model is intended for a lightweight
prototype; language coverage and factual accuracy must be checked against the
source. Summaries can still contain errors despite the grounding instructions.
