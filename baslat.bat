@echo off
cd /d "%~dp0"
start "" http://127.0.0.1:8000
"%USERPROFILE%\.config\herd\bin\php85\php.exe" artisan serve
