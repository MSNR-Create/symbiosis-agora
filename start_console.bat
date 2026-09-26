@echo off
rem Symbiosis Agora console launcher (opens http://127.0.0.1:8765)
rem NOTE: keep this file ASCII-only with CRLF line endings, or cmd.exe may fail to parse it.
cd /d "%~dp0"
set "PY=.venv\Scripts\python.exe"
if not exist "%PY%" (
  echo [ERROR] .venv not found. Run these in this folder first:
  echo     python -m venv .venv
  echo     .venv\Scripts\pip install -r requirements.txt
  pause
  exit /b 1
)
title Symbiosis Agora Console
set "PYTHONIOENCODING=utf-8"
chcp 65001 > nul
"%PY%" orchestrator\console_server.py %*
echo.
echo Console stopped. (exit code %ERRORLEVEL%)
pause
