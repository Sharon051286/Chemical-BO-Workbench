@echo off
REM One-click RAGFlow deployment launcher (Windows)
REM Double-click this file inside the docker/ragflow folder to start.
powershell -ExecutionPolicy Bypass -File "%~dp0deploy.ps1"
pause
