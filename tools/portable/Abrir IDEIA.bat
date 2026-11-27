@echo off
chcp 65001 >nul
title IDEIA Contabilidade
cd /d "%~dp0"
if exist "php\windows\php.exe" (
  "php\windows\php.exe" -c "%~dp0php\windows\php.ini" -d extension_dir="%~dp0php\windows\ext" "app\tools\portable\launcher.php"
) else (
  where php >nul 2>nul && php "app\tools\portable\launcher.php" || echo   ERRO: falta a pasta php\windows. Descarregue de novo o pacote para Windows.
)
if errorlevel 1 pause
